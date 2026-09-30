<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (SSL or STARTTLS + AUTH LOGIN) so the app runs on any
 * cPanel host without Composer. Sends multipart/alternative (text + HTML).
 */
final class Mailer
{
    public static function ready(): bool
    {
        $m = Config::get('mail', []);
        return !empty($m['host']) && !empty($m['user']) && !empty($m['pass']) && $m['pass'] !== 'CHANGE_ME';
    }

    public static function send(string $to, string $subject, string $html, string $text): void
    {
        $m = Config::get('mail');
        if (!self::ready()) throw new ApiError('ارسال ایمیل هنوز روی سرور تنظیم نشده است.', 503);
        if (Config::get('env') === 'development' && !empty($m['log_only'])) {
            file_put_contents(sys_get_temp_dir() . '/repol-mail.log', "TO: $to\nSUBJECT: $subject\n$text\n\n", FILE_APPEND);
            return;
        }
        $host = ($m['encryption'] === 'ssl' ? 'ssl://' : '') . $m['host'];
        $fp = @stream_socket_client("$host:{$m['port']}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]));
        if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
        stream_set_timeout($fp, 20);
        $read = function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $out;
        };
        $cmd = function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP error on ' . strtok($c, ' ') . ': ' . trim($r));
            return $r;
        };
        $read();
        $ehlo = parse_url(Config::get('app_url'), PHP_URL_HOST) ?: 'localhost';
        $cmd("EHLO $ehlo", [250]);
        if ($m['encryption'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('STARTTLS failed');
            $cmd("EHLO $ehlo", [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($m['user']), [334]);
        $cmd(base64_encode($m['pass']), [235]);
        $cmd('MAIL FROM:<' . $m['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $boundary = 'rp_' . bin2hex(random_bytes(8));
        $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc($m['from_name']) . ' <' . $m['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehlo . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $r = $read();
        if ((int)substr($r, 0, 3) !== 250) throw new RuntimeException('SMTP DATA rejected: ' . trim($r));
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
    }

    /** Render an email template from api/templates with {{placeholders}}. */
    public static function render(string $name, array $vars): string
    {
        $tpl = file_get_contents(dirname(__DIR__) . "/templates/$name.html");
        $vars += ['app_url' => Config::get('app_url'), 'year' => self::faDigits(self::jalaliYear())];
        return preg_replace_callback('/{{\s*(\w+)\s*}}/', fn($m) => htmlspecialchars((string)($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8'), $tpl);
    }

    public static function faDigits(string|int $s): string
    {
        return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    private static function jalaliYear(): int
    {
        // Persian new year falls on 20/21 March.
        $y = (int)date('Y');
        return (int)date('n') > 3 || ((int)date('n') === 3 && (int)date('j') >= 21) ? $y - 621 : $y - 622;
    }

    public static function sendCode(string $to, string $code, string $purpose): void
    {
        $spaced = self::faDigits(implode(' ', str_split($code)));
        $title = $purpose === 'register' ? 'تأیید ایمیل و ساخت حساب' : 'کد ورود به پنل';
        $lead = $purpose === 'register'
            ? 'برای تکمیل ثبت‌نام در ریپل، این کد را در صفحه‌ی ساخت حساب وارد کن.'
            : 'یک درخواست ورود به حساب ریپل تو ثبت شد. برای ورود، این کد را وارد کن.';
        $html = self::render('code', ['title' => $title, 'lead' => $lead, 'code' => $spaced, 'minutes' => self::faDigits(10)]);
        $text = "$title\n\n$lead\n\nکد: $code\n\nاین کد تا ۱۰ دقیقه معتبر است. اگر این درخواست از طرف تو نبوده، این ایمیل را نادیده بگیر.\n\n" . Config::get('app_url');
        self::send($to, "$code کد تأیید ریپل", $html, $text);
    }

    public static function sendNotice(string $to, string $title, string $body, string $cta = 'ورود به پنل', string $url = ''): void
    {
        $html = self::render('notice', ['title' => $title, 'body' => $body, 'cta' => $cta, 'url' => $url ?: Config::get('app_url') . '/app']);
        self::send($to, $title, $html, "$title\n\n$body\n\n" . ($url ?: Config::get('app_url') . '/app'));
    }
}
