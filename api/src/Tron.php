<?php
declare(strict_types=1);

/** TRON (TRX) helpers: address validation and confirmed-transfer lookup via TronGrid. */
final class Tron
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private static function headers(): array
    {
        $k = Config::get('tron.api_key');
        return $k ? ['TRON-PRO-API-KEY: ' . $k] : [];
    }

    private static function api(): string
    {
        return rtrim((string)Config::get('tron.api', 'https://api.trongrid.io'), '/');
    }

    /** Base58 → raw bytes, without bcmath/gmp. */
    public static function base58Decode(string $s): ?string
    {
        $bytes = [0];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $carry = strpos(self::ALPHABET, $s[$i]);
            if ($carry === false) return null;
            for ($j = 0, $m = count($bytes); $j < $m; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }
        for ($i = 0; $i < strlen($s) && $s[$i] === '1'; $i++) $bytes[] = 0;
        return implode('', array_map('chr', array_reverse($bytes)));
    }

    /** "T..." address → "41..." hex, or null when the checksum fails. */
    public static function toHex(string $addr): ?string
    {
        $raw = self::base58Decode($addr);
        if ($raw === null || strlen($raw) !== 25) return null;
        [$payload, $check] = [substr($raw, 0, 21), substr($raw, 21)];
        if (substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4) !== $check) return null;
        return bin2hex($payload);
    }

    public static function validAddress(string $addr): bool
    {
        $hex = preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr) ? self::toHex($addr) : null;
        return $hex !== null && str_starts_with($hex, '41');
    }

    public static function sameAddress(string $a, string $b): bool
    {
        $norm = fn($x) => str_starts_with($x, 'T') ? self::toHex($x) : strtolower($x);
        return $a !== '' && $b !== '' && $norm($a) === $norm($b);
    }

    /**
     * A confirmed (solidified) native TRX transfer, or null.
     * @return array{to:string, amount:int, time:int}|null
     */
    public static function transfer(string $txid): ?array
    {
        [$s, $tx] = Http::post(self::api() . '/walletsolidity/gettransactionbyid', ['value' => $txid], self::headers());
        if ($s !== 200 || empty($tx['raw_data']['contract'][0])) return null;
        $c = $tx['raw_data']['contract'][0];
        if (($c['type'] ?? '') !== 'TransferContract' || ($tx['ret'][0]['contractRet'] ?? '') !== 'SUCCESS') return null;
        $v = $c['parameter']['value'];
        return ['to' => (string)$v['to_address'], 'amount' => (int)$v['amount'], 'time' => (int)($tx['raw_data']['timestamp'] ?? 0)];
    }

    /** Cron: match confirmed incoming transfers to pending invoices by their unique amount. */
    public static function scanIncoming(): int
    {
        $biz = Billing::business();
        if (!$biz['cryptoEnabled'] || !$biz['cryptoAddress']) return 0;
        $pending = Db::all("SELECT id, amount, created FROM orders WHERE method='crypto' AND status='pending' AND expires > ?", [Util::now() - 6 * 3600000]);
        if (!$pending) return 0;
        $since = min(array_map(fn($o) => (int)$o['created'], $pending)) - 10 * 60000;
        [$s, $j] = Http::get(self::api() . '/v1/accounts/' . $biz['cryptoAddress'] . '/transactions?only_to=true&only_confirmed=true&limit=100&min_timestamp=' . $since, self::headers());
        if ($s !== 200) return 0;
        $matched = 0;
        foreach ($j['data'] ?? [] as $tx) {
            $c = $tx['raw_data']['contract'][0] ?? null;
            if (!$c || $c['type'] !== 'TransferContract' || ($tx['ret'][0]['contractRet'] ?? '') !== 'SUCCESS') continue;
            $v = $c['parameter']['value'];
            if (!self::sameAddress((string)$v['to_address'], $biz['cryptoAddress'])) continue;
            foreach ($pending as $o) {
                if ((int)$o['amount'] === (int)$v['amount'] && !Db::value("SELECT 1 FROM orders WHERE txid=?", [$tx['txID']])) {
                    Db::run("UPDATE orders SET txid=? WHERE id=? AND txid IS NULL", [$tx['txID'], $o['id']]);
                    if (Billing::fulfil($o['id'], 'TRX خودکار')) $matched++;
                }
            }
        }
        return $matched;
    }

    public static function balance(string $addr): int
    {
        [$s, $j] = Http::get(self::api() . '/v1/accounts/' . $addr, self::headers());
        if ($s !== 200) throw new ApiError('استعلام موجودی از شبکه ممکن نشد.', 502);
        return (int)($j['data'][0]['balance'] ?? 0);
    }
}

/** Admin reports to a Telegram chat. Silent no-op when not configured. */
final class Telegram
{
    public static function admin(string $text): void
    {
        $token = Config::get('telegram.bot_token');
        $chat = Config::get('telegram.admin_chat_id');
        if (!$token || !$chat) return;
        try {
            Http::post("https://api.telegram.org/bot$token/sendMessage", ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true], [], 6);
        } catch (Throwable $e) {
            error_log('telegram: ' . $e->getMessage());
        }
    }
}
