<?php
declare(strict_types=1);

// Repol API front controller. Every /api/* request lands here (see .htaccess).
foreach (['Core', 'Mailer', 'Auth', 'Billing', 'Tron', 'Instagram', 'Engine', 'Workspace', 'Admin'] as $f) require __DIR__ . "/src/$f.php";

$dev = false;
try {
    $dev = Config::get('env') === 'development';
    ini_set('display_errors', '0');
    date_default_timezone_set('Asia/Tehran');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $method = $_SERVER['REQUEST_METHOD'];
    $path = '/' . trim(preg_replace('#^.*?/api#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');

    // Public endpoints that must not touch the session.
    if ($path === '/webhook/instagram') $method === 'GET' ? Instagram::verifyWebhook() : Instagram::webhook();
    if (preg_match('#^/f/([0-9a-f]{48})(/.*)?$#', $path, $m)) Workspace::publicFile($m[1]);
    if ($path === '/health') Http::json(['ok' => true, 'db' => (bool)Db::value('SELECT 1'), 'time' => Util::now()]);

    Auth::start();
    if ($method !== 'GET') Auth::guardWrite();

    $id = '([A-Za-z0-9-]{8,64})';
    $routes = [
        ['GET', '/status', fn() => Auth::status()],
        ['GET', '/catalog', fn() => Billing::catalog()],
        ['POST', '/auth/setup', fn() => Auth::setup()],
        ['POST', '/auth/login', fn() => Auth::loginPassword()],
        ['POST', '/auth/code', fn() => Auth::requestLoginCode()],
        ['POST', '/auth/verify', fn() => Auth::verifyLoginCode()],
        ['POST', '/auth/register', fn() => Auth::register()],
        ['POST', '/auth/register/verify', fn() => Auth::registerVerify()],
        ['POST', '/auth/logout', fn() => Auth::logout()],
        ['PUT', '/auth/password', fn() => Auth::changePassword()],

        ['GET', '/data', fn() => Workspace::data()],
        ['GET', '/updates', fn() => Workspace::updates()],
        ['PUT', '/settings', fn() => Workspace::settings()],
        ['PUT', '/profile', fn() => Workspace::profile()],
        ['POST', '/notifications/read', fn() => Workspace::readNotifications()],
        ['POST', '/rules', fn() => Workspace::saveRule(null)],
        ['PUT', "/rules/$id", fn($r) => Workspace::saveRule($r)],
        ['DELETE', "/rules/$id", fn($r) => Workspace::deleteRule($r)],
        ['POST', '/simulate', fn() => Workspace::simulate()],
        ['POST', '/files', fn() => Workspace::upload()],
        ['GET', "/files/$id", fn($f) => Workspace::download($f)],
        ['DELETE', "/files/$id", fn($f) => Workspace::deleteFile($f)],
        ['POST', '/contacts', fn() => Workspace::addContact()],
        ['POST', "/contacts/$id/reply", fn($c) => Workspace::reply($c)],
        ['POST', '/templates', fn() => Workspace::addTemplate()],

        ['POST', '/orders', fn() => Billing::createOrder()],
        ['POST', "/orders/$id/proof", fn($o) => Billing::proof($o)],
        ['POST', "/orders/$id/verify", fn($o) => Billing::verifyTrx($o)],
        ['GET', '/pay/zarinpal/callback', fn() => Zarinpal::callback()],

        ['GET', '/instagram/connect', fn() => Instagram::connect()],
        ['GET', '/instagram/callback', fn() => Instagram::callback()],
        ['POST', '/instagram/disconnect', fn() => Instagram::disconnect()],

        ['GET', '/admin/overview', fn() => Admin::overview()],
        ['PUT', "/admin/users/$id", fn($u) => Admin::updateUser($u)],
        ['POST', "/admin/users/$id/subscription", fn($u) => Billing::grant($u)],
        ['POST', "/admin/orders/$id/review", fn($o) => Billing::review($o)],
        ['POST', '/admin/announcements', fn() => Admin::announce()],
        ['PUT', '/admin/business', fn() => Billing::saveBusiness()],
        ['GET', '/admin/wallet-balance', fn() => Admin::walletBalance()],
        ['GET', '/admin/diagnostics', fn() => Admin::diagnostics()],
    ];

    $allowed = false;
    foreach ($routes as [$verb, $pattern, $handler]) {
        if (!preg_match("#^$pattern$#", $path, $m)) continue;
        $allowed = true;
        if ($verb !== $method) continue;
        Http::json($handler(...array_slice($m, 1)));
    }
    throw new ApiError($allowed ? 'این روش درخواست پشتیبانی نمی‌شود.' : 'مسیر پیدا نشد.', $allowed ? 405 : 404);
} catch (ApiError $e) {
    Http::json(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[repol] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::json(['error' => $dev ? $e->getMessage() : 'خطای غیرمنتظره در سرور رخ داد. دوباره امتحان کن.'], 500);
}
