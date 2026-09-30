<?php
declare(strict_types=1);

// cPanel → Cron Jobs → every minute:
//   /usr/local/bin/php /home/CPANELUSER/public_html/api/cron.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['Core', 'Mailer', 'Auth', 'Billing', 'Tron', 'Instagram', 'Engine', 'Workspace'] as $f) require __DIR__ . "/src/$f.php";
date_default_timezone_set('Asia/Tehran');

$lock = fopen(sys_get_temp_dir() . '/repol-cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit; // previous run still busy

$log = fn(string $m) => fwrite(STDOUT, date('c') . " $m\n");
$minute = (int)date('i');
$hour = (int)date('G');

try { $n = Tron::scanIncoming(); if ($n) $log("trx matched: $n"); } catch (Throwable $e) { $log('trx: ' . $e->getMessage()); }

if ($minute % 10 === 0) {
    $log('orders expired: ' . Billing::expireStale());
    Db::run("DELETE FROM seen_events WHERE created < ?", [Util::now() - 3 * 86400000]);
    Db::run("DELETE FROM otp_codes WHERE expires < ?", [Util::now()]);
}

if ($minute === 7) {
    try { $log('ig tokens refreshed: ' . Instagram::refreshTokens()); } catch (Throwable $e) { $log('ig refresh: ' . $e->getMessage()); }
    // Subscription reminders: 3 days and 1 day before expiry.
    foreach ([3, 1] as $d) {
        $from = Util::now() + ($d - 1) * Billing::DAY; $to = Util::now() + $d * Billing::DAY;
        foreach (Db::all("SELECT * FROM users WHERE sub_expires BETWEEN ? AND ? AND status='active'", [$from, $to]) as $u) {
            $key = 'remind:' . $u['id'] . ':' . $d . ':' . date('Ymd');
            try { Db::run("INSERT INTO seen_events (id, created) VALUES (?, ?)", [$key, Util::now()]); } catch (PDOException) { continue; }
            $t = "اشتراکت تا {$d} روز دیگر تمام می‌شود";
            $b = 'برای اینکه دایرکت هوشمند بدون وقفه کار کند، از بخش «اشتراک و صورت‌حساب» تمدید کن.';
            Util::notify($u['id'], $t, $b, 'warning');
            try { if (Mailer::ready()) Mailer::sendNotice($u['email'], $t, $b, 'تمدید اشتراک', Config::get('app_url') . '/app/billing'); } catch (Throwable) {}
        }
    }
}

if ($hour === 9 && $minute === 0) {
    $y = strtotime('yesterday') * 1000; $t = strtotime('today') * 1000;
    $c = fn(string $sql) => (int)Db::value($sql, [$y, $t]);
    Telegram::admin("📊 گزارش دیروز ریپل\n"
        . 'کاربر تازه: ' . $c("SELECT COUNT(*) FROM users WHERE created BETWEEN ? AND ?") . "\n"
        . 'پاسخ خودکار: ' . $c("SELECT COUNT(*) FROM events WHERE type='reply_sent' AND created BETWEEN ? AND ?") . "\n"
        . 'فایل ارسالی: ' . $c("SELECT COUNT(*) FROM events WHERE type='file_sent' AND created BETWEEN ? AND ?") . "\n"
        . 'فروش (تومان): ' . number_format(intdiv($c("SELECT COALESCE(SUM(amount_irr),0) FROM orders WHERE status='approved' AND paid_at BETWEEN ? AND ?"), 10)));
}
