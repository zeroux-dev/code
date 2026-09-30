<?php
declare(strict_types=1);

final class Auth
{
    public const ACCESS = ['dashboard', 'rules', 'inbox', 'files', 'analytics', 'connections', 'contacts', 'templates'];
    private static ?array $user = null;

    public static function start(): void
    {
        $secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string)Config::get('app_url'), 'https://');
        session_name('repol_sid');
        session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
        session_start();
    }

    /** Reject cross-site writes: the panel always sends JSON from the same origin. */
    public static function guardWrite(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $allowed = parse_url((string)Config::get('app_url'), PHP_URL_HOST);
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $oh = parse_url($origin, PHP_URL_HOST);
            if ($oh !== $allowed && $oh !== explode(':', $host)[0]) throw new ApiError('درخواست از مبدأ نامعتبر.', 403);
        }
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new ApiError('درخواست نامعتبر است.', 415);
    }

    public static function user(): ?array
    {
        if (self::$user) return self::$user;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) return null;
        $u = Db::one("SELECT * FROM users WHERE id=?", [$id]);
        if (!$u || (int)$u['session_version'] !== (int)($_SESSION['sv'] ?? -1) || $u['status'] !== 'active') {
            $_SESSION = [];
            return null;
        }
        return self::$user = $u;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) throw new ApiError('برای ادامه وارد حسابت شو.', 401);
        return $u;
    }

    public static function admin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') throw new ApiError('این بخش مخصوص مدیریت اصلی است.', 403);
        return $u;
    }

    /** Page permission check for feature endpoints. */
    public static function can(array $u, string $page): void
    {
        if ($u['role'] === 'admin') return;
        $perms = json_decode($u['permissions'] ?? 'null', true) ?? self::ACCESS;
        if (!in_array($page, $perms, true)) throw new ApiError('دسترسی این بخش برای حسابت فعال نیست.', 403);
    }

    /** Features that act on Instagram need an active subscription (admins exempt). */
    public static function requireSubscription(array $u): void
    {
        if ($u['role'] === 'admin') return;
        if ((int)$u['sub_expires'] < Util::now()) throw new ApiError('برای فعال‌سازی دایرکت هوشمند، یک اشتراک فعال لازم است.', 402);
    }

    private static function login(array $u): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        $_SESSION['sv'] = (int)$u['session_version'];
        self::$user = null;
    }

    public static function status(): array
    {
        $hasAdmin = (bool)Db::value("SELECT 1 FROM users WHERE role='admin' LIMIT 1");
        return [
            'setup' => !$hasAdmin,
            'setupAllowed' => false,
            'emailReady' => Mailer::ready(),
            'authenticated' => self::user() !== null,
            'preview' => false,
        ];
    }

    private static function password(array $b): string
    {
        $p = (string)($b['password'] ?? '');
        if (mb_strlen($p) < 12 || mb_strlen($p) > 128) throw new ApiError('رمز عبور باید بین ۱۲ تا ۱۲۸ کاراکتر باشد.');
        return password_hash($p, PASSWORD_DEFAULT);
    }

    private static function newUser(string $name, string $email, string $hash, string $role): array
    {
        $id = Util::uuid();
        $now = Util::now();
        Db::run("INSERT INTO users (id, name, email, password_hash, role, permissions, settings, email_verified, created) VALUES (?,?,?,?,?,?,?,?,?)",
            [$id, $name, $email, $hash, $role, json_encode(self::ACCESS), json_encode(['workspace' => 'استودیوی ' . $name]), $now, $now]);
        Util::notify($id, 'به ریپل خوش آمدی', 'حسابت آماده است. از بخش «اتصال‌ها» پیج اینستاگرامت را وصل کن و اولین سناریوی دایرکت هوشمند را بساز.', 'release');
        return Db::one("SELECT * FROM users WHERE id=?", [$id]);
    }

    public static function setup(): array
    {
        $b = Http::body();
        if (Db::value("SELECT 1 FROM users WHERE role='admin' LIMIT 1")) throw new ApiError('راه‌اندازی قبلاً انجام شده است.', 409);
        Util::limit('setup:' . Http::ip(), 5, 900);
        $token = (string)Config::get('setup_token', '');
        if ($token === '' || $token === 'CHANGE_ME' || !hash_equals($token, (string)($b['setupToken'] ?? ''))) throw new ApiError('کد راه‌اندازی سرور درست نیست.', 403);
        $name = Util::str($b, 'name', 60, true, 'نام');
        $email = Util::email($b);
        if (Db::value("SELECT 1 FROM users WHERE email=?", [$email])) throw new ApiError('این ایمیل قبلاً ثبت شده است.', 409);
        $u = self::newUser($name, $email, self::password($b), 'admin');
        Util::audit($u, 'حساب مدیریت اصلی ساخته شد');
        self::login($u);
        return ['ok' => true];
    }

    public static function loginPassword(): array
    {
        $b = Http::body();
        $email = Util::email($b);
        Util::limit('login:' . Http::ip(), 20, 900);
        Util::limit('login:' . $email, 8, 900, 'تلاش‌های ناموفق زیادی برای این حساب ثبت شد. ۱۵ دقیقه بعد دوباره امتحان کن یا با کد ایمیلی وارد شو.');
        $u = Db::one("SELECT * FROM users WHERE email=?", [$email]);
        if (!$u) password_hash('timing-equaliser', PASSWORD_DEFAULT); // same cost as a real check
        if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) throw new ApiError('ایمیل یا رمز عبور درست نیست.', 401);
        if ($u['status'] !== 'active') throw new ApiError('این حساب غیرفعال شده است. با پشتیبانی تماس بگیر.', 403);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) Db::run("UPDATE users SET password_hash=? WHERE id=?", [password_hash((string)$b['password'], PASSWORD_DEFAULT), $u['id']]);
        self::login($u);
        return ['ok' => true];
    }

    private static function issueCode(string $email, string $purpose, ?array $payload = null): void
    {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = Util::now();
        Db::run("DELETE FROM otp_codes WHERE email=? AND purpose=?", [$email, $purpose]);
        Db::run("INSERT INTO otp_codes (email, purpose, code_hash, payload, expires, created) VALUES (?,?,?,?,?,?)",
            [$email, $purpose, password_hash($code, PASSWORD_DEFAULT), $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $now + 600000, $now]);
        Mailer::sendCode($email, $code, $purpose);
    }

    private static function consumeCode(string $email, string $purpose, string $code): array
    {
        $row = Db::one("SELECT * FROM otp_codes WHERE email=? AND purpose=? ORDER BY id DESC LIMIT 1", [$email, $purpose]);
        if (!$row || (int)$row['expires'] < Util::now()) throw new ApiError('کد منقضی شده است. یک کد تازه بگیر.', 400);
        if ((int)$row['attempts'] >= 5) throw new ApiError('تعداد تلاش برای این کد تمام شد. یک کد تازه بگیر.', 429);
        Db::run("UPDATE otp_codes SET attempts=attempts+1 WHERE id=?", [$row['id']]);
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $row['code_hash'])) throw new ApiError('کد وارد شده درست نیست.', 400);
        Db::run("DELETE FROM otp_codes WHERE id=?", [$row['id']]);
        return json_decode($row['payload'] ?? 'null', true) ?? [];
    }

    private static function code(array $b): string
    {
        return strtr(trim((string)($b['code'] ?? '')), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /** Login by one-time email code. Never reveals whether an email exists. */
    public static function requestLoginCode(): array
    {
        $b = Http::body();
        $email = Util::email($b);
        Util::limit('code:' . Http::ip(), 10, 3600);
        Util::limit('code:' . $email, 3, 900, 'برای این ایمیل به‌تازگی کد فرستاده شده. چند دقیقه صبر کن.');
        $u = Db::one("SELECT id, status FROM users WHERE email=?", [$email]);
        if ($u && $u['status'] === 'active') self::issueCode($email, 'login');
        return ['ok' => true];
    }

    public static function verifyLoginCode(): array
    {
        $b = Http::body();
        $email = Util::email($b);
        Util::limit('verify:' . Http::ip(), 30, 900);
        self::consumeCode($email, 'login', self::code($b));
        $u = Db::one("SELECT * FROM users WHERE email=?", [$email]);
        if (!$u || $u['status'] !== 'active') throw new ApiError('این حساب در دسترس نیست.', 403);
        if (!(int)$u['email_verified']) Db::run("UPDATE users SET email_verified=? WHERE id=?", [Util::now(), $u['id']]);
        self::login($u);
        return ['ok' => true];
    }

    /** Step 1 of sign-up: validate, then e-mail a code. The account is created only after verification. */
    public static function register(): array
    {
        $b = Http::body();
        $name = Util::str($b, 'name', 60, true, 'نام');
        $email = Util::email($b);
        $hash = self::password($b);
        Util::limit('register:' . Http::ip(), 8, 3600);
        Util::limit('register:' . $email, 3, 900, 'برای این ایمیل به‌تازگی کد فرستاده شده. چند دقیقه صبر کن.');
        if (Db::value("SELECT 1 FROM users WHERE email=?", [$email])) throw new ApiError('با این ایمیل قبلاً حساب ساخته شده است. از صفحه‌ی ورود وارد شو.', 409);
        self::issueCode($email, 'register', ['name' => $name, 'hash' => $hash]);
        return ['verify' => true, 'email' => $email];
    }

    public static function registerVerify(): array
    {
        $b = Http::body();
        $email = Util::email($b);
        Util::limit('verify:' . Http::ip(), 30, 900);
        $p = self::consumeCode($email, 'register', self::code($b));
        if (Db::value("SELECT 1 FROM users WHERE email=?", [$email])) throw new ApiError('این ایمیل قبلاً ثبت شده است.', 409);
        $u = self::newUser((string)$p['name'], $email, (string)$p['hash'], 'user');
        Util::audit($u, 'ثبت‌نام کاربر تازه', ['email' => $email]);
        Telegram::admin("👤 کاربر تازه: {$u['name']} ({$email})");
        self::login($u);
        return ['ok' => true];
    }

    public static function changePassword(): array
    {
        $u = self::require();
        $b = Http::body();
        if (!password_verify((string)($b['currentPassword'] ?? ''), $u['password_hash'])) throw new ApiError('رمز فعلی درست نیست.', 400);
        $hash = self::password($b);
        Db::run("UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?", [$hash, $u['id']]);
        self::login(Db::one("SELECT * FROM users WHERE id=?", [$u['id']]));
        return ['ok' => true];
    }

    public static function logout(): array
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000] + array_intersect_key($p, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
        }
        session_destroy();
        return ['ok' => true];
    }
}
