<?php
declare(strict_types=1);

final class Workspace
{
    private const FILE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'video/mp4', 'audio/mpeg', 'audio/ogg', 'audio/mp4', 'audio/aac', 'text/plain'];
    private const FILE_MAX = 25 * 1024 * 1024;     // Instagram's attachment ceiling
    private const QUOTA = 2 * 1024 * 1024 * 1024;  // per workspace

    private static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z', intdiv($ms, 1000)) ?: '';
    }

    public static function owner(array $u): array
    {
        return [
            'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'],
            'permissions' => $u['role'] === 'admin' ? Auth::ACCESS : (json_decode($u['permissions'] ?? 'null', true) ?? Auth::ACCESS),
            'phone' => $u['phone'], 'company' => $u['company'], 'bio' => $u['bio'], 'avatar' => $u['avatar'],
        ];
    }

    public static function subscription(array $u): array
    {
        $exp = (int)$u['sub_expires'];
        $left = max(0, (int)ceil(($exp - Util::now()) / Billing::DAY));
        return ['daysRemaining' => $left, 'plan' => $left ? $u['sub_plan'] : 'free', 'expires' => $exp, 'active' => $left > 0 || $u['role'] === 'admin'];
    }

    public static function rule(array $r): array
    {
        return [
            'id' => $r['id'], 'name' => $r['name'], 'trigger' => $r['trigger_type'], 'keywords' => json_decode($r['keywords'], true) ?: [],
            'match' => $r['match_type'], 'response' => $r['response'], 'enabled' => (bool)$r['enabled'],
            'commentReply' => $r['comment_reply'], 'followGate' => (bool)$r['follow_gate'], 'gateMessage' => $r['gate_message'],
            'fileId' => $r['file_id'], 'runs' => (int)$r['runs'], 'created' => self::iso((int)$r['created']),
        ];
    }

    public static function data(): array
    {
        $u = Auth::require();
        $id = $u['id'];
        $ig = Db::one("SELECT username, token_expires, connected FROM ig_accounts WHERE user_id=?", [$id]);
        $settings = array_merge(['workspace' => 'استودیوی ' . $u['name'], 'theme' => 'dark', 'motion' => true, 'compact' => false], json_decode($u['settings'] ?? '{}', true) ?: []);
        return [
            'owner' => self::owner($u),
            'settings' => $settings,
            'rules' => array_map([self::class, 'rule'], Db::all("SELECT * FROM rules WHERE user_id=? ORDER BY created DESC", [$id])),
            'files' => array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'type' => $f['type'], 'date' => self::iso((int)$f['created'])],
                Db::all("SELECT id, name, size, type, created FROM files WHERE user_id=? ORDER BY created DESC", [$id])),
            'events' => array_map(fn($e) => ['id' => (string)$e['id'], 'type' => $e['type'], 'text' => ($e['contact'] ? $e['contact'] . ' · ' : '') . $e['text'], 'date' => self::iso((int)$e['created'])],
                Db::all("SELECT * FROM events WHERE user_id=? ORDER BY id DESC LIMIT 60", [$id])),
            'contacts' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'handle' => $c['handle'], 'tag' => $c['tag'], 'note' => $c['note'], 'follows' => (bool)$c['follows'], 'created' => (int)$c['created']],
                Db::all("SELECT * FROM contacts WHERE user_id=? ORDER BY last_seen DESC, created DESC LIMIT 500", [$id])),
            'templates' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'category' => $t['category'], 'text' => $t['text'], 'created' => (int)$t['created']],
                Db::all("SELECT * FROM templates WHERE user_id=? ORDER BY created DESC", [$id])),
            'notifications' => array_map(fn($n) => ['id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'type' => $n['type'], 'created' => (int)$n['created'], 'read_at' => $n['read_at'] ? (int)$n['read_at'] : null],
                Db::all("SELECT * FROM notifications WHERE user_id=? ORDER BY created DESC LIMIT 100", [$id])),
            'orders' => array_map([Billing::class, 'present'], Db::all("SELECT o.*, u.name, u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.user_id=? ORDER BY o.created DESC LIMIT 100", [$id])),
            'wallet' => ['balance' => (int)$u['balance'], 'currency' => 'IRR', 'ledger' => array_map(fn($l) => ['amount' => (int)$l['amount'], 'reason' => $l['reason'], 'created' => (int)$l['created']],
                Db::all("SELECT * FROM wallet_ledger WHERE user_id=? ORDER BY id DESC LIMIT 100", [$id]))],
            'subscription' => self::subscription($u),
            'business' => Billing::catalog(),
            'connections' => ['instagram' => (bool)$ig, 'telegram' => (bool)Config::get('telegram.bot_token'), 'storage' => true, 'email' => Mailer::ready()],
            'instagramAccount' => $ig ? ['username' => $ig['username'], 'expires' => (int)$ig['token_expires'], 'connected' => (int)$ig['connected']] : null,
            'integrations' => ['instagramReady' => Instagram::ready()],
            'conversations' => self::conversations($id),
            'stats' => self::stats($id),
        ];
    }

    private static function conversations(string $userId): array
    {
        $contacts = Db::all("SELECT c.* FROM contacts c WHERE c.user_id=? AND EXISTS (SELECT 1 FROM messages m WHERE m.contact_id=c.id) ORDER BY c.last_seen DESC LIMIT 40", [$userId]);
        return array_map(function ($c) {
            $msgs = array_reverse(Db::all("SELECT direction, text, created FROM messages WHERE contact_id=? ORDER BY id DESC LIMIT 40", [$c['id']]));
            return ['id' => $c['id'], 'name' => $c['name'], 'handle' => $c['handle'], 'follows' => (bool)$c['follows'], 'lastSeen' => (int)$c['last_seen'],
                'messages' => array_map(fn($m) => ['out' => $m['direction'] === 'out', 'text' => $m['text'], 'created' => (int)$m['created']], $msgs)];
        }, $contacts);
    }

    /** Totals for the current window vs the previous one, plus daily series for the chart. */
    private static function stats(string $userId): array
    {
        $now = Util::now();
        $out = [];
        foreach (['week' => 7, 'month' => 30] as $key => $days) {
            $from = $now - $days * Billing::DAY;
            $prev = $from - $days * Billing::DAY;
            $count = fn(string $types, int $a, int $b) => (int)Db::value("SELECT COUNT(*) FROM events WHERE user_id=? AND FIND_IN_SET(type, ?) AND created BETWEEN ? AND ?", [$userId, $types, $a, $b]);
            $people = fn(int $a, int $b) => (int)Db::value("SELECT COUNT(DISTINCT contact_id) FROM messages WHERE user_id=? AND direction='in' AND created BETWEEN ? AND ?", [$userId, $a, $b]);
            $delta = fn(int $cur, int $old) => $old ? round(($cur - $old) / $old * 100, 1) : ($cur ? 100.0 : 0.0);
            $conv = $count('comment,dm,story', $from, $now);
            $replies = $count('reply_sent', $from, $now);
            $engaged = $people($from, $now);
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $a = strtotime('today', intdiv($now, 1000)) * 1000 - $i * Billing::DAY;
                $series[] = ['day' => $a, 'in' => $count('comment,dm,story', $a, $a + Billing::DAY - 1), 'out' => $count('reply_sent', $a, $a + Billing::DAY - 1)];
            }
            $out[$key] = [
                'conversations' => $conv, 'conversationsDelta' => $delta($conv, $count('comment,dm,story', $prev, $from)),
                'replies' => $replies, 'repliesDelta' => $delta($replies, $count('reply_sent', $prev, $from)),
                'engaged' => $engaged, 'engagedDelta' => $delta($engaged, $people($prev, $from)),
                'follows' => $count('follow_ok', $from, $now), 'files' => $count('file_sent', $from, $now),
                'series' => $series,
            ];
        }
        $out['rulesReady'] = (int)Db::value("SELECT COUNT(*) FROM rules WHERE user_id=? AND enabled=1", [$userId]);
        $out['contacts'] = (int)Db::value("SELECT COUNT(*) FROM contacts WHERE user_id=?", [$userId]);
        return $out;
    }

    public static function updates(): array
    {
        $u = Auth::require();
        return ['unread' => (int)Db::value("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$u['id']]), 'user' => self::owner($u)];
    }

    /* ---------------------------- scenarios ---------------------------- */

    public static function saveRule(?string $id): array
    {
        $u = Auth::require();
        Auth::can($u, 'rules');
        $b = Http::body();
        $trigger = (string)($b['trigger'] ?? '');
        if (!in_array($trigger, ['comment', 'dm', 'story'], true)) throw new ApiError('نوع شروع گفتگو معتبر نیست.');
        $kw = $b['keywords'] ?? [];
        if (is_string($kw)) $kw = preg_split('/[,،\n]/u', $kw);
        $kw = array_values(array_unique(array_filter(array_map(fn($k) => mb_substr(trim((string)$k), 0, 100), (array)$kw), 'strlen')));
        if (!$kw || count($kw) > 20) throw new ApiError('بین ۱ تا ۲۰ کلمه‌ی کلیدی وارد کن.');
        $name = Util::str($b, 'name', 120, true, 'نام سناریو');
        $response = Util::str($b, 'response', 1000, true, 'متن پاسخ');
        $fileId = ($b['fileId'] ?? '') ?: null;
        if ($fileId && !Db::value("SELECT 1 FROM files WHERE id=? AND user_id=?", [$fileId, $u['id']])) throw new ApiError('فایل انتخاب‌شده پیدا نشد.');
        $vals = [
            $name, $trigger, json_encode($kw, JSON_UNESCAPED_UNICODE), ($b['match'] ?? '') === 'exact' ? 'exact' : 'contains', $response,
            Util::str($b, 'commentReply', 300), !empty($b['followGate']) ? 1 : 0, Util::str($b, 'gateMessage', 600), $fileId,
            array_key_exists('enabled', $b) ? (!empty($b['enabled']) ? 1 : 0) : 1, Util::now(),
        ];
        if ($id) {
            if (!Db::value("SELECT 1 FROM rules WHERE id=? AND user_id=?", [$id, $u['id']])) throw new ApiError('سناریو پیدا نشد.', 404);
            Db::run("UPDATE rules SET name=?, trigger_type=?, keywords=?, match_type=?, response=?, comment_reply=?, follow_gate=?, gate_message=?, file_id=?, enabled=?, updated=? WHERE id=? AND user_id=?", [...$vals, $id, $u['id']]);
        } else {
            if ((int)Db::value("SELECT COUNT(*) FROM rules WHERE user_id=?", [$u['id']]) >= 200) throw new ApiError('حداکثر ۲۰۰ سناریو می‌توانی داشته باشی.');
            $id = Util::uuid();
            Db::run("INSERT INTO rules (name, trigger_type, keywords, match_type, response, comment_reply, follow_gate, gate_message, file_id, enabled, updated, id, user_id, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [...$vals, $id, $u['id'], Util::now()]);
        }
        return self::rule(Db::one("SELECT * FROM rules WHERE id=?", [$id]));
    }

    public static function deleteRule(string $id): array
    {
        $u = Auth::require();
        Db::run("DELETE FROM rules WHERE id=? AND user_id=?", [$id, $u['id']]);
        return ['ok' => true];
    }

    public static function simulate(): array
    {
        $u = Auth::require();
        $b = Http::body();
        $trigger = in_array($b['trigger'] ?? '', ['comment', 'dm', 'story'], true) ? $b['trigger'] : 'comment';
        $r = Engine::match(Db::all("SELECT * FROM rules WHERE user_id=? ORDER BY created ASC", [$u['id']]), $trigger, Util::str($b, 'text', 1000, true, 'متن'));
        return ['match' => $r ? self::rule($r) : null];
    }

    /* ---------------------------- files ---------------------------- */

    private static function storage(): string
    {
        $dir = rtrim((string)Config::get('storage_dir'), '/');
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('storage dir not writable');
        return $dir;
    }

    public static function upload(): array
    {
        $u = Auth::require();
        Auth::can($u, 'files');
        $b = Http::body();
        $bin = base64_decode((string)($b['content'] ?? ''), true);
        if ($bin === false || $bin === '') throw new ApiError('محتوای فایل خوانده نشد.');
        if (strlen($bin) > self::FILE_MAX) throw new ApiError('حجم فایل حداکثر ۲۵ مگابایت است.');
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: '';
        if ($type === 'audio/x-m4a') $type = 'audio/mp4';
        if (!in_array($type, self::FILE_TYPES, true)) throw new ApiError('این نوع فایل پشتیبانی نمی‌شود. تصویر، PDF، ویدیوی MP4 یا فایل صوتی بفرست.');
        $used = (int)Db::value("SELECT COALESCE(SUM(size),0) FROM files WHERE user_id=?", [$u['id']]);
        if ($used + strlen($bin) > self::QUOTA) throw new ApiError('فضای ذخیره‌سازی‌ات پر شده است.');
        $name = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/u', '_', Util::str($b, 'name', 180, true, 'نام فایل'));
        $id = Util::uuid();
        $rel = substr($id, 0, 2) . '/' . $id;
        @mkdir(self::storage() . '/' . substr($id, 0, 2), 0750, true);
        if (file_put_contents(self::storage() . '/' . $rel, $bin) === false) throw new RuntimeException('write failed');
        Db::run("INSERT INTO files (id, user_id, name, type, size, path, public_token, created) VALUES (?,?,?,?,?,?,?,?)",
            [$id, $u['id'], $name, $type, strlen($bin), $rel, bin2hex(random_bytes(24)), Util::now()]);
        return ['id' => $id];
    }

    public static function download(string $id): never
    {
        $u = Auth::require();
        $f = Db::one("SELECT * FROM files WHERE id=? AND user_id=?", [$id, $u['id']]);
        if (!$f) throw new ApiError('فایل پیدا نشد.', 404);
        self::stream($f, true);
    }

    /** Unguessable public link: Instagram fetches attachments from here. */
    public static function publicFile(string $token): never
    {
        $f = preg_match('/^[0-9a-f]{48}$/', $token) ? Db::one("SELECT * FROM files WHERE public_token=?", [$token]) : null;
        if (!$f) {
            http_response_code(404);
            exit;
        }
        self::stream($f, false);
    }

    private static function stream(array $f, bool $attachment): never
    {
        $path = self::storage() . '/' . $f['path'];
        if (!is_file($path)) throw new ApiError('فایل روی سرور پیدا نشد.', 404);
        header('Content-Type: ' . $f['type']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: ' . ($attachment ? 'private, no-store' : 'public, max-age=86400'));
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($f['name']));
        readfile($path);
        exit;
    }

    public static function deleteFile(string $id): array
    {
        $u = Auth::require();
        $f = Db::one("SELECT * FROM files WHERE id=? AND user_id=?", [$id, $u['id']]);
        if (!$f) throw new ApiError('فایل پیدا نشد.', 404);
        @unlink(self::storage() . '/' . $f['path']);
        Db::run("DELETE FROM files WHERE id=?", [$id]);
        Db::run("UPDATE rules SET file_id=NULL WHERE file_id=?", [$id]);
        return ['ok' => true];
    }

    /* ---------------------------- people & content ---------------------------- */

    public static function addContact(): array
    {
        $u = Auth::require();
        $b = Http::body();
        Db::run("INSERT INTO contacts (id, user_id, name, handle, tag, note, last_seen, created) VALUES (?,?,?,?,?,?,?,?)",
            [Util::uuid(), $u['id'], Util::str($b, 'name', 100, true, 'نام'), ltrim(Util::str($b, 'handle', 80), '@'), Util::str($b, 'tag', 40), Util::str($b, 'note', 500), Util::now(), Util::now()]);
        return ['ok' => true];
    }

    public static function addTemplate(): array
    {
        $u = Auth::require();
        $b = Http::body();
        Db::run("INSERT INTO templates (id, user_id, name, category, text, created) VALUES (?,?,?,?,?,?)",
            [Util::uuid(), $u['id'], Util::str($b, 'name', 100, true, 'نام قالب'), Util::str($b, 'category', 40), Util::str($b, 'text', 1000, true, 'متن قالب'), Util::now()]);
        return ['ok' => true];
    }

    public static function settings(): array
    {
        $u = Auth::require();
        $b = Http::body();
        $s = json_decode($u['settings'] ?? '{}', true) ?: [];
        if (isset($b['workspace'])) $s['workspace'] = Util::str($b, 'workspace', 60, true, 'نام فضای کار');
        if (isset($b['theme'])) $s['theme'] = in_array($b['theme'], ['dark', 'light', 'system'], true) ? $b['theme'] : 'dark';
        if (isset($b['motion'])) $s['motion'] = (bool)$b['motion'];
        if (isset($b['compact'])) $s['compact'] = (bool)$b['compact'];
        Db::run("UPDATE users SET settings=? WHERE id=?", [json_encode($s, JSON_UNESCAPED_UNICODE), $u['id']]);
        return array_merge(['workspace' => '', 'theme' => 'dark', 'motion' => true, 'compact' => false], $s);
    }

    public static function profile(): array
    {
        $u = Auth::require();
        $b = Http::body();
        $avatar = in_array($b['avatar'] ?? '', ['violet', 'mint', 'blue', 'rose', 'amber', 'ink'], true) ? $b['avatar'] : $u['avatar'];
        $phone = Util::str($b, 'phone', 20);
        if ($phone !== '' && !preg_match('/^[+0-9۰-۹ ]{7,20}$/u', $phone)) throw new ApiError('شماره موبایل معتبر نیست.');
        Db::run("UPDATE users SET name=?, phone=?, company=?, bio=?, avatar=? WHERE id=?",
            [Util::str($b, 'name', 60, true, 'نام'), $phone, Util::str($b, 'company', 120), Util::str($b, 'bio', 500), $avatar, $u['id']]);
        return self::owner(Db::one("SELECT * FROM users WHERE id=?", [$u['id']]));
    }

    public static function readNotifications(): array
    {
        $u = Auth::require();
        $id = Http::body()['id'] ?? null;
        if ($id) Db::run("UPDATE notifications SET read_at=? WHERE id=? AND user_id=? AND read_at IS NULL", [Util::now(), $id, $u['id']]);
        else Db::run("UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL", [Util::now(), $u['id']]);
        return ['ok' => true];
    }

    /** Manual reply from the inbox (inside Instagram's 24h window). */
    public static function reply(string $contactId): array
    {
        $u = Auth::require();
        Auth::can($u, 'inbox');
        Auth::requireSubscription($u);
        $c = Db::one("SELECT * FROM contacts WHERE id=? AND user_id=?", [$contactId, $u['id']]);
        if (!$c || !$c['ig_user_id']) throw new ApiError('این مخاطب از اینستاگرام نیامده است.', 404);
        $acc = Instagram::account($u['id']);
        if (!$acc) throw new ApiError('ابتدا پیج اینستاگرامت را وصل کن.', 409);
        $text = Util::str(Http::body(), 'text', 1000, true, 'متن پیام');
        [$ok, $err] = Instagram::send($acc, ['id' => $c['ig_user_id']], ['text' => $text]);
        if (!$ok) throw new ApiError('اینستاگرام پیام را نپذیرفت: ' . $err . ' (پاسخ دستی فقط تا ۲۴ ساعت بعد از آخرین پیام مخاطب ممکن است.)', 502);
        Db::run("INSERT INTO messages (user_id, contact_id, direction, text, created) VALUES (?,?,?,?,?)", [$u['id'], $c['id'], 'out', $text, Util::now()]);
        return ['ok' => true];
    }
}
