<?php
declare(strict_types=1);

final class Admin
{
    public static function overview(): array
    {
        Auth::admin();
        $users = Db::all("SELECT u.*, (SELECT COUNT(*) FROM rules r WHERE r.user_id=u.id) AS rule_count FROM users u ORDER BY u.created DESC LIMIT 1000");
        return [
            'business' => Billing::business(),
            'users' => array_map(function ($u) {
                $sub = Workspace::subscription($u);
                return Workspace::owner($u) + [
                    'status' => $u['status'], 'daysRemaining' => $sub['daysRemaining'], 'expires' => (int)$u['sub_expires'], 'plan' => $sub['plan'],
                    'created' => (int)$u['created'], 'ruleCount' => (int)$u['rule_count'], 'balance' => (int)$u['balance'],
                    'instagram' => Db::value("SELECT username FROM ig_accounts WHERE user_id=?", [$u['id']]),
                ];
            }, $users),
            'orders' => array_map([Billing::class, 'present'], Db::all("SELECT o.*, u.name, u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created DESC LIMIT 300")),
            'announcements' => array_map(fn($a) => $a + ['reads' => (int)Db::value("SELECT COUNT(*) FROM notifications WHERE batch_id=? AND read_at IS NOT NULL", [$a['batch_id']])],
                Db::all("SELECT * FROM announcements ORDER BY created DESC LIMIT 50")),
            'audit' => array_map(fn($a) => ['id' => (string)$a['id'], 'actorName' => $a['actor_name'], 'action' => $a['action'], 'created' => (int)$a['created'], 'data' => json_decode($a['data'] ?? '{}', true)],
                Db::all("SELECT * FROM audit ORDER BY id DESC LIMIT 100")),
            'metrics' => [
                'revenueMonthIRR' => (int)Db::value("SELECT COALESCE(SUM(amount_irr),0) FROM orders WHERE status='approved' AND kind='subscription' AND paid_at > ?", [Util::now() - 30 * Billing::DAY]),
                'activeSubscribers' => (int)Db::value("SELECT COUNT(*) FROM users WHERE sub_expires > ?", [Util::now()]),
                'connectedPages' => (int)Db::value("SELECT COUNT(*) FROM ig_accounts"),
                'messagesToday' => (int)Db::value("SELECT COUNT(*) FROM events WHERE type='reply_sent' AND created > ?", [strtotime('today') * 1000]),
            ],
        ];
    }

    public static function updateUser(string $id): array
    {
        $admin = Auth::admin();
        $b = Http::body();
        $u = Db::one("SELECT * FROM users WHERE id=?", [$id]);
        if (!$u) throw new ApiError('کاربر پیدا نشد.', 404);
        if ($u['id'] === $admin['id'] && ($b['status'] ?? 'active') !== 'active') throw new ApiError('نمی‌توانی حساب مدیریت خودت را غیرفعال کنی.');
        $status = in_array($b['status'] ?? '', ['active', 'suspended'], true) ? $b['status'] : $u['status'];
        $perms = array_values(array_intersect(Auth::ACCESS, (array)($b['permissions'] ?? [])));
        if (!in_array('dashboard', $perms, true)) $perms[] = 'dashboard';
        Db::run("UPDATE users SET status=?, permissions=?, session_version=session_version+? WHERE id=?",
            [$status, json_encode($perms), $status !== $u['status'] ? 1 : 0, $id]);
        Util::audit($admin, "دسترسی {$u['name']} به‌روز شد", ['status' => $status, 'permissions' => $perms]);
        return ['ok' => true];
    }

    public static function announce(): array
    {
        $admin = Auth::admin();
        $b = Http::body();
        $title = Util::str($b, 'title', 120, true, 'عنوان');
        $body = Util::str($b, 'body', 1500, true, 'متن پیام');
        $type = in_array($b['type'] ?? '', ['info', 'release', 'success', 'warning'], true) ? $b['type'] : 'info';
        $target = ($b['userId'] ?? '') ?: null;
        $ids = $target ? Db::all("SELECT id FROM users WHERE id=?", [$target]) : Db::all("SELECT id FROM users WHERE status='active'");
        if (!$ids) throw new ApiError('مخاطبی برای این اعلان پیدا نشد.');
        $batch = Util::uuid();
        Db::tx(function () use ($ids, $title, $body, $type, $batch, $target) {
            foreach ($ids as $r) Util::notify($r['id'], $title, $body, $type, $batch);
            Db::run("INSERT INTO announcements (batch_id, title, body, type, user_id, recipients, created) VALUES (?,?,?,?,?,?,?)",
                [$batch, $title, $body, $type, $target, count($ids), Util::now()]);
        });
        Util::audit($admin, "اعلان «{$title}» برای " . count($ids) . ' نفر منتشر شد');
        return ['ok' => true, 'recipients' => count($ids)];
    }

    public static function walletBalance(): array
    {
        Auth::admin();
        $addr = Billing::business()['cryptoAddress'];
        if (!$addr) throw new ApiError('آدرس کیف پول TRON هنوز ثبت نشده است.');
        return ['balanceSun' => Tron::balance($addr), 'checkedAt' => Util::now()];
    }

    /** Can this host reach every outside service we depend on? (Iranian hosts often cannot reach Meta.) */
    public static function diagnostics(): array
    {
        Auth::admin();
        $probe = function (string $url): array {
            $t = microtime(true);
            try {
                [$s] = Http::get($url, [], 8);
                return ['ok' => $s > 0 && $s < 500, 'status' => $s, 'ms' => (int)((microtime(true) - $t) * 1000)];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'ms' => (int)((microtime(true) - $t) * 1000)];
            }
        };
        $m = Config::get('mail', []);
        $smtp = ['ok' => false];
        if (Mailer::ready()) {
            $fp = @stream_socket_client((($m['encryption'] ?? '') === 'ssl' ? 'ssl://' : '') . $m['host'] . ':' . $m['port'], $no, $err, 6);
            $smtp = ['ok' => (bool)$fp, 'error' => $fp ? null : $err];
            if ($fp) fclose($fp);
        }
        return [
            'php' => PHP_VERSION,
            'extensions' => array_combine($x = ['pdo_mysql', 'curl', 'sodium', 'fileinfo', 'mbstring', 'intl'], array_map('extension_loaded', $x)),
            'instagram' => $probe('https://graph.instagram.com/'),
            'zarinpal' => $probe('https://payment.zarinpal.com/'),
            'trongrid' => $probe('https://api.trongrid.io/wallet/getnowblock'),
            'telegram' => $probe('https://api.telegram.org/'),
            'smtp' => $smtp,
            'storageWritable' => is_writable(dirname(rtrim((string)Config::get('storage_dir'), '/'))) || is_writable((string)Config::get('storage_dir')),
            'relay' => Config::get('relay.url') ? $probe(rtrim((string)Config::get('relay.url'), '/') . '/health') : ['ok' => false, 'error' => 'not configured'],
            'configured' => ['relay' => (bool)Config::get('relay.url'), 'mail' => Mailer::ready(), 'instagram' => Instagram::ready(), 'zarinpal' => (bool)Config::get('zarinpal.merchant_id'), 'telegram' => (bool)Config::get('telegram.bot_token')],
        ];
    }
}
