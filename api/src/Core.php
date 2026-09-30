<?php
declare(strict_types=1);

/** Configuration loaded once from outside the web root when possible. */
final class Config
{
    private static ?array $c = null;

    public static function all(): array
    {
        if (self::$c !== null) return self::$c;
        $candidates = [
            getenv('REPOL_CONFIG') ?: '',
            dirname(__DIR__, 3) . '/repol-config.php', // /home/user/repol-config.php when api/ lives in public_html
            dirname(__DIR__) . '/config.php',
        ];
        foreach ($candidates as $file) {
            if ($file && is_file($file)) {
                $c = require $file;
                if (!is_array($c)) throw new RuntimeException('config missing');
                self::$file = $file;
                return self::$c = $c;
            }
        }
        throw new RuntimeException('config missing');
    }

    private static ?string $file = null;

    /** Where the config was found (for the health check); never the contents. */
    public static function source(): string
    {
        self::all();
        return str_contains((string)self::$file, 'public_html') ? 'api/config.php' : 'outside public_html';
    }

    /** Keep a private error log next to the config so the owner can read it in File Manager. */
    public static function logError(Throwable $e): void
    {
        $dir = self::$file ? dirname(self::$file) : null;
        if (!$dir || !is_writable($dir)) return;
        @file_put_contents($dir . '/repol-error.log', date('Y-m-d H:i:s') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND);
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $v = self::all();
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }
}

/** Thin PDO wrapper with automatic schema migration. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        self::$pdo = new PDO(Config::get('db.dsn'), Config::get('db.user'), Config::get('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET NAMES utf8mb4");
        self::migrate();
        return self::$pdo;
    }

    private static function migrate(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
        $version = md5($sql);
        try {
            $row = self::$pdo->query("SELECT v FROM kv WHERE k='schema_version'")->fetch();
            if ($row && $row['v'] === $version) return;
        } catch (PDOException) {
            // kv table does not exist yet
        }
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
            self::$pdo->exec($stmt);
        }
        self::$pdo->prepare("REPLACE INTO kv (k, v) VALUES ('schema_version', ?)")->execute([$version]);
    }

    public static function run(string $sql, array $args = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::run($sql, $args)->fetch();
        return $r ?: null;
    }

    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    public static function value(string $sql, array $args = []): mixed
    {
        $v = self::run($sql, $args)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function kv(string $k, mixed $default = null): mixed
    {
        $v = self::value("SELECT v FROM kv WHERE k=?", [$k]);
        return $v === null ? $default : json_decode($v, true);
    }

    public static function setKv(string $k, mixed $v): void
    {
        self::run("REPLACE INTO kv (k, v) VALUES (?, ?)", [$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
    }
}

/** A user-facing error: the message is shown in the panel as-is. */
final class ApiError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static array $params = [];
    private static ?array $body = null;

    public static function body(): array
    {
        if (self::$body !== null) return self::$body;
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 16 * 1024 * 1024) throw new ApiError('حجم درخواست بیش از حد مجاز است.', 413);
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) throw new ApiError('درخواست نامعتبر است.');
        return self::$body = $data;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Send the response now and keep working (webhooks must answer Meta fast). */
    public static function finishEarly(string $body = 'EVENT_RECEIVED'): void
    {
        ignore_user_abort(true);
        http_response_code(200);
        header('Content-Type: text/plain');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        else { @ob_end_flush(); flush(); }
    }

    public static function get(string $url, array $headers = [], int $timeout = 15): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    public static function post(string $url, mixed $payload, array $headers = [], int $timeout = 15, bool $form = false): array
    {
        return self::request('POST', $url, $payload, $headers, $timeout, $form);
    }

    /** Outbound HTTP: returns [status, decoded json|null, raw]. */
    public static function request(string $method, string $url, mixed $payload, array $headers, int $timeout, bool $form = false): array
    {
        $ch = curl_init($url);
        $h = array_merge(['Accept: application/json'], $headers);
        if ($payload !== null) {
            if ($form) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            } else {
                $h[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => $h,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("HTTP $method $url failed: $err");
        return [$status, json_decode($raw, true), $raw];
    }
}

final class Util
{
    public static function now(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function str(array $b, string $k, int $max, bool $required = false, string $label = 'این فیلد'): string
    {
        $v = trim((string)($b[$k] ?? ''));
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        if ($required && $v === '') throw new ApiError("$label را وارد کن.");
        if (mb_strlen($v) > $max) throw new ApiError("$label حداکثر $max کاراکتر است.");
        return $v;
    }

    public static function email(array $b): string
    {
        $e = mb_strtolower(trim((string)($b['email'] ?? '')));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 190) throw new ApiError('ایمیل معتبر وارد کن.');
        return $e;
    }

    /** Persian/Arabic normalisation used for keyword matching. */
    public static function normalize(string $s): string
    {
        $s = class_exists('Normalizer') ? (Normalizer::normalize($s, Normalizer::FORM_KC) ?: $s) : $s;
        $s = str_replace(['ي', 'ك', "\u{200c}", "\u{200d}", 'ۀ', 'ة'], ['ی', 'ک', ' ', ' ', 'ه', 'ه'], $s);
        $s = strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    public static function encrypt(string $plain): string
    {
        $key = sodium_crypto_generichash(Config::get('secret'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public static function decrypt(string $cipher): string
    {
        $key = sodium_crypto_generichash(Config::get('secret'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $raw = base64_decode($cipher, true);
        $plain = $raw ? sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key) : false;
        if ($plain === false) throw new RuntimeException('cannot decrypt');
        return $plain;
    }

    /** Fixed-window limiter; throws a friendly 429. */
    public static function limit(string $key, int $max, int $windowSec, string $msg = 'تعداد تلاش‌ها زیاد است. چند دقیقه بعد دوباره امتحان کن.'): void
    {
        $now = self::now();
        $k = substr(hash('sha256', $key), 0, 64);
        Db::run("DELETE FROM rate_limits WHERE reset_at < ?", [$now]);
        Db::run("INSERT INTO rate_limits (k, hits, reset_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1", [$k, $now + $windowSec * 1000]);
        if ((int)Db::value("SELECT hits FROM rate_limits WHERE k=?", [$k]) > $max) throw new ApiError($msg, 429);
    }

    public static function audit(?array $actor, string $action, array $data = []): void
    {
        Db::run("INSERT INTO audit (actor_id, actor_name, action, data, created) VALUES (?,?,?,?,?)",
            [$actor['id'] ?? null, $actor['name'] ?? 'سیستم', $action, json_encode($data, JSON_UNESCAPED_UNICODE), self::now()]);
    }

    public static function notify(string $userId, string $title, string $body, string $type = 'info', ?string $batch = null): void
    {
        Db::run("INSERT INTO notifications (id, user_id, title, body, type, batch_id, created) VALUES (?,?,?,?,?,?,?)",
            [self::uuid(), $userId, $title, $body, $type, $batch, self::now()]);
    }

    public static function event(string $userId, string $type, string $text, string $contact = '', ?string $ruleId = null): void
    {
        Db::run("INSERT INTO events (user_id, type, text, contact, rule_id, created) VALUES (?,?,?,?,?,?)",
            [$userId, $type, mb_substr($text, 0, 500), mb_substr($contact, 0, 80), $ruleId, self::now()]);
    }

    public static function toman(int $irr): string
    {
        return number_format(intdiv($irr, 10));
    }
}
