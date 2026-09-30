<?php
declare(strict_types=1);

/**
 * Instagram API with Instagram Login (graph.instagram.com).
 * Scopes: instagram_business_basic, instagram_business_manage_messages, instagram_business_manage_comments.
 */
final class Instagram
{
    public const SCOPES = 'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments';

    public static function ready(): bool
    {
        return (bool)Config::get('instagram.app_id') && (bool)Config::get('instagram.app_secret');
    }

    private static function graph(): string
    {
        return rtrim((string)Config::get('instagram.graph', 'https://graph.instagram.com/v23.0'), '/');
    }

    private static function redirectUri(): string
    {
        return Config::get('app_url') . '/api/instagram/callback';
    }

    /** GET /api/instagram/connect → Instagram consent screen. */
    public static function connect(): never
    {
        $u = Auth::user();
        if (!$u) Http::redirect(Config::get('app_url') . '/login');
        if (!self::ready()) Http::redirect(Config::get('app_url') . '/app/connections?instagram=not-configured');
        $_SESSION['ig_state'] = bin2hex(random_bytes(16));
        Http::redirect('https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id' => Config::get('instagram.app_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $_SESSION['ig_state'],
            'enable_fb_login' => 0,
            'force_authentication' => 1,
        ]));
    }

    public static function callback(): never
    {
        $back = fn(string $q) => Http::redirect(Config::get('app_url') . '/app/connections?' . $q);
        $u = Auth::user();
        if (!$u) Http::redirect(Config::get('app_url') . '/login');
        $state = (string)($_GET['state'] ?? '');
        if (!$state || !hash_equals((string)($_SESSION['ig_state'] ?? ''), $state)) $back('instagram=state');
        unset($_SESSION['ig_state']);
        if (!empty($_GET['error'])) $back('instagram=denied');
        $code = preg_replace('/#_$/', '', (string)($_GET['code'] ?? ''));
        try {
            [$s, $j] = Http::post('https://api.instagram.com/oauth/access_token', [
                'client_id' => Config::get('instagram.app_id'),
                'client_secret' => Config::get('instagram.app_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => self::redirectUri(),
                'code' => $code,
            ], [], 15, true);
            $short = $j['access_token'] ?? ($j['data'][0]['access_token'] ?? null);
            if ($s !== 200 || !$short) throw new RuntimeException('code exchange: ' . json_encode($j));
            [$s, $j] = Http::get('https://graph.instagram.com/access_token?' . http_build_query([
                'grant_type' => 'ig_exchange_token', 'client_secret' => Config::get('instagram.app_secret'), 'access_token' => $short]));
            if ($s !== 200 || empty($j['access_token'])) throw new RuntimeException('long-lived exchange: ' . json_encode($j));
            $token = $j['access_token'];
            $expires = Util::now() + (int)($j['expires_in'] ?? 5184000) * 1000;
            [$s, $me] = Http::get(self::graph() . '/me?' . http_build_query(['fields' => 'user_id,username', 'access_token' => $token]));
            if ($s !== 200 || empty($me['user_id'])) throw new RuntimeException('me: ' . json_encode($me));
            $owner = Db::value("SELECT user_id FROM ig_accounts WHERE ig_user_id=?", [(string)$me['user_id']]);
            if ($owner && $owner !== $u['id']) $back('instagram=taken');
            Db::run("REPLACE INTO ig_accounts (user_id, ig_user_id, username, token, token_expires, connected) VALUES (?,?,?,?,?,?)",
                [$u['id'], (string)$me['user_id'], (string)$me['username'], Util::encrypt($token), $expires, Util::now()]);
            // Ask Meta to deliver this account's comments and messages to our webhook.
            Http::post(self::graph() . '/me/subscribed_apps?' . http_build_query(['subscribed_fields' => 'comments,messages,messaging_postbacks', 'access_token' => $token]), null);
            Util::event($u['id'], 'system', 'پیج @' . $me['username'] . ' به ریپل وصل شد.');
            Util::audit($u, 'اتصال اینستاگرام @' . $me['username']);
            $back('instagram=connected');
        } catch (Throwable $e) {
            error_log('instagram connect: ' . $e->getMessage());
            $back('instagram=failed');
        }
    }

    public static function disconnect(): array
    {
        $u = Auth::require();
        Db::run("DELETE FROM ig_accounts WHERE user_id=?", [$u['id']]);
        Util::event($u['id'], 'system', 'اتصال اینستاگرام قطع شد.');
        return ['ok' => true];
    }

    public static function account(string $userId): ?array
    {
        $a = Db::one("SELECT * FROM ig_accounts WHERE user_id=?", [$userId]);
        if (!$a) return null;
        $a['token'] = Util::decrypt($a['token']);
        return $a;
    }

    /* ---------------------------- webhook ---------------------------- */

    public static function verifyWebhook(): never
    {
        if (($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals((string)Config::get('instagram.verify_token'), (string)($_GET['hub_verify_token'] ?? ''))) {
            header('Content-Type: text/plain');
            echo (string)($_GET['hub_challenge'] ?? '');
            exit;
        }
        http_response_code(403);
        exit;
    }

    /** Validate the signature, answer Meta immediately, then process. */
    public static function webhook(): never
    {
        $raw = file_get_contents('php://input') ?: '';
        $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $raw, (string)Config::get('instagram.app_secret'));
        if (!self::ready() || !hash_equals($expected, $sig)) {
            http_response_code(403);
            exit;
        }
        Http::finishEarly();
        $payload = json_decode($raw, true) ?: [];
        foreach ($payload['entry'] ?? [] as $entry) {
            try {
                Engine::handleEntry($entry);
            } catch (Throwable $e) {
                error_log('webhook entry: ' . $e->getMessage());
            }
        }
        exit;
    }

    /* ---------------------------- send API ---------------------------- */

    /** @return array [ok, error-message] */
    public static function send(array $acc, array $recipient, array $message): array
    {
        [$s, $j] = Http::post(self::graph() . '/' . $acc['ig_user_id'] . '/messages', ['recipient' => $recipient, 'message' => $message], ['Authorization: Bearer ' . $acc['token']], 12);
        if ($s === 200 && !isset($j['error'])) return [true, ''];
        error_log('ig send failed: ' . json_encode($j));
        return [false, (string)($j['error']['message'] ?? "HTTP $s")];
    }

    public static function replyToComment(array $acc, string $commentId, string $text): void
    {
        Http::post(self::graph() . '/' . $commentId . '/replies', ['message' => $text], ['Authorization: Bearer ' . $acc['token']], 10);
    }

    public static function profile(array $acc, string $igsid): array
    {
        [$s, $j] = Http::get(self::graph() . '/' . $igsid . '?' . http_build_query(['fields' => 'name,username,is_user_follow_business', 'access_token' => $acc['token']]), [], 8);
        return $s === 200 ? $j : [];
    }

    /** Cron: long-lived tokens last 60 days; refresh anything expiring within 15 days. */
    public static function refreshTokens(): int
    {
        $n = 0;
        foreach (Db::all("SELECT user_id FROM ig_accounts WHERE token_expires < ?", [Util::now() + 15 * 86400000]) as $row) {
            $acc = self::account($row['user_id']);
            [$s, $j] = Http::get('https://graph.instagram.com/refresh_access_token?' . http_build_query(['grant_type' => 'ig_refresh_token', 'access_token' => $acc['token']]));
            if ($s === 200 && !empty($j['access_token'])) {
                Db::run("UPDATE ig_accounts SET token=?, token_expires=? WHERE user_id=?", [Util::encrypt($j['access_token']), Util::now() + (int)$j['expires_in'] * 1000, $row['user_id']]);
                $n++;
            } elseif ((int)Db::value("SELECT token_expires FROM ig_accounts WHERE user_id=?", [$row['user_id']]) < Util::now()) {
                Util::notify($row['user_id'], 'اتصال اینستاگرام منقضی شد', 'برای ادامه‌ی کار دایرکت هوشمند، از بخش اتصال‌ها دوباره پیجت را وصل کن.', 'warning');
            }
        }
        return $n;
    }
}
