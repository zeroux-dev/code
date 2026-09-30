<?php
declare(strict_types=1);

/**
 * Smart-direct engine.
 *   comment on a post  → optional public reply + private DM
 *   DM / story reply   → DM
 *   follow gate        → DM with a «فالو کردم» button; the reward is sent only
 *                        after Instagram confirms the follow.
 */
final class Engine
{
    public const GATE_WORDS = ['فالو کردم', 'فالوکردم', 'فالو شد', 'followed', 'done'];

    /** Keyword match exactly as the panel's tester does it. */
    public static function match(array $rules, string $trigger, string $text): ?array
    {
        $t = Util::normalize($text);
        foreach ($rules as $r) {
            if (!(int)$r['enabled'] || $r['trigger_type'] !== $trigger) continue;
            foreach (json_decode($r['keywords'], true) ?: [] as $k) {
                $k = Util::normalize((string)$k);
                if ($k === '*' || ($k !== '' && ($r['match_type'] === 'exact' ? $t === $k : str_contains($t, $k)))) return $r;
            }
        }
        return null;
    }

    private static function once(string $key): bool
    {
        try {
            Db::run("INSERT INTO seen_events (id, created) VALUES (?, ?)", [substr($key, 0, 120), Util::now()]);
            return true;
        } catch (PDOException) {
            return false; // Meta retries deliveries; each event is handled once
        }
    }

    public static function handleEntry(array $entry): void
    {
        $igId = (string)($entry['id'] ?? '');
        $ownerId = Db::value("SELECT user_id FROM ig_accounts WHERE ig_user_id=?", [$igId]);
        if (!$ownerId) return;
        $owner = Db::one("SELECT * FROM users WHERE id=?", [$ownerId]);
        $acc = Instagram::account($ownerId);
        if (!$owner || !$acc) return;

        foreach ($entry['changes'] ?? [] as $ch) {
            if (($ch['field'] ?? '') === 'comments') self::onComment($owner, $acc, $ch['value'] ?? []);
        }
        foreach ($entry['messaging'] ?? [] as $m) {
            $sender = (string)($m['sender']['id'] ?? '');
            if ($sender === '' || $sender === $acc['ig_user_id'] || !empty($m['message']['is_echo'])) continue;
            if (isset($m['postback'])) self::onPostback($owner, $acc, $sender, $m['postback']);
            elseif (isset($m['message'])) self::onMessage($owner, $acc, $sender, $m['message']);
        }
    }

    private static function active(array $owner): bool
    {
        if ($owner['role'] === 'admin' || (int)$owner['sub_expires'] > Util::now()) return true;
        return false;
    }

    private static function rules(string $userId): array
    {
        return Db::all("SELECT * FROM rules WHERE user_id=? AND enabled=1 ORDER BY created ASC", [$userId]);
    }

    private static function contact(array $owner, string $igsid, string $username = ''): array
    {
        $c = Db::one("SELECT * FROM contacts WHERE user_id=? AND ig_user_id=?", [$owner['id'], $igsid]);
        if ($c) {
            Db::run("UPDATE contacts SET last_seen=?" . ($username && !$c['handle'] ? ", handle=?" : "") . " WHERE id=?",
                $username && !$c['handle'] ? [Util::now(), $username, $c['id']] : [Util::now(), $c['id']]);
            return $c;
        }
        $id = Util::uuid();
        Db::run("INSERT IGNORE INTO contacts (id, user_id, name, handle, tag, ig_user_id, last_seen, created) VALUES (?,?,?,?,?,?,?,?)",
            [$id, $owner['id'], $username ?: 'مخاطب اینستاگرام', $username, 'تازه', $igsid, Util::now(), Util::now()]);
        return Db::one("SELECT * FROM contacts WHERE user_id=? AND ig_user_id=?", [$owner['id'], $igsid]);
    }

    private static function log(array $owner, array $contact, string $dir, string $text): void
    {
        Db::run("INSERT INTO messages (user_id, contact_id, direction, text, created) VALUES (?,?,?,?,?)",
            [$owner['id'], $contact['id'], $dir, mb_substr($text, 0, 2000), Util::now()]);
    }

    private static function onComment(array $owner, array $acc, array $v): void
    {
        $commentId = (string)($v['id'] ?? '');
        $from = (string)($v['from']['id'] ?? '');
        $username = (string)($v['from']['username'] ?? '');
        if (!$commentId || !$from || $from === $acc['ig_user_id'] || !self::once('c:' . $commentId)) return;
        $text = (string)($v['text'] ?? '');
        $contact = self::contact($owner, $from, $username);
        self::log($owner, $contact, 'in', '💬 ' . $text);
        Util::event($owner['id'], 'comment', "کامنت «" . mb_substr($text, 0, 60) . "»", '@' . $username);
        $rule = self::match(self::rules($owner['id']), 'comment', $text);
        if (!$rule) return;
        if (!self::active($owner)) {
            Util::event($owner['id'], 'skipped', 'اشتراک فعال نیست؛ پاسخ ارسال نشد.', '@' . $username, $rule['id']);
            return;
        }
        if ($rule['comment_reply'] !== '') Instagram::replyToComment($acc, $commentId, $rule['comment_reply']);
        self::respond($owner, $acc, $contact, $rule, ['comment_id' => $commentId]);
    }

    private static function onMessage(array $owner, array $acc, string $sender, array $msg): void
    {
        if (!self::once('m:' . ($msg['mid'] ?? bin2hex(random_bytes(8))))) return;
        $text = (string)($msg['text'] ?? ($msg['quick_reply']['payload'] ?? ''));
        $contact = self::contact($owner, $sender);
        self::log($owner, $contact, 'in', $text !== '' ? $text : '📎 پیوست');
        if (!self::active($owner)) return;

        $payload = (string)($msg['quick_reply']['payload'] ?? '');
        if (str_starts_with($payload, 'GATE:')) {
            self::checkGate($owner, $acc, $contact, substr($payload, 5));
            return;
        }
        if ($pending = self::pendingGate($owner, $sender)) {
            if (in_array(Util::normalize($text), array_map([Util::class, 'normalize'], self::GATE_WORDS), true)) {
                self::checkGate($owner, $acc, $contact, $pending['rule_id']);
                return;
            }
        }
        $trigger = isset($msg['reply_to']['story']) ? 'story' : 'dm';
        Util::event($owner['id'], $trigger, ($trigger === 'story' ? 'پاسخ به استوری: ' : 'دایرکت: ') . mb_substr($text, 0, 60), self::label($contact));
        $rule = self::match(self::rules($owner['id']), $trigger, $text);
        if ($rule) self::respond($owner, $acc, $contact, $rule, ['id' => $sender]);
    }

    private static function onPostback(array $owner, array $acc, string $sender, array $pb): void
    {
        if (!self::once('p:' . ($pb['mid'] ?? bin2hex(random_bytes(8))))) return;
        $contact = self::contact($owner, $sender);
        $payload = (string)($pb['payload'] ?? '');
        if (str_starts_with($payload, 'GATE:') && self::active($owner)) self::checkGate($owner, $acc, $contact, substr($payload, 5));
    }

    private static function pendingGate(array $owner, string $igsid): ?array
    {
        return Db::one("SELECT * FROM follow_gates WHERE user_id=? AND igsid=? AND done IS NULL AND created > ? ORDER BY id DESC LIMIT 1",
            [$owner['id'], $igsid, Util::now() - 7 * 86400000]);
    }

    private static function label(array $contact): string
    {
        return $contact['handle'] ? '@' . $contact['handle'] : $contact['name'];
    }

    /** First response to a matched rule: either the gate, or the reward. */
    private static function respond(array $owner, array $acc, array $contact, array $rule, array $recipient): void
    {
        Db::run("UPDATE rules SET runs=runs+1 WHERE id=?", [$rule['id']]);
        if ((int)$rule['follow_gate']) {
            $profile = Instagram::profile($acc, $contact['ig_user_id']);
            if (!empty($profile['is_user_follow_business'])) {
                Db::run("UPDATE contacts SET follows=1 WHERE id=?", [$contact['id']]);
                self::deliver($owner, $acc, $contact, $rule, $recipient);
                return;
            }
            $gateText = $rule['gate_message'] !== '' ? $rule['gate_message'] : 'سلام! 👋 برای دریافت، اول پیج ما را فالو کن و بعد دکمه‌ی «فالو کردم» را بزن.';
            $ok = self::sendButton($acc, $recipient, $gateText, 'فالو کردم ✅', 'GATE:' . $rule['id']);
            Db::run("INSERT INTO follow_gates (user_id, igsid, rule_id, created) VALUES (?,?,?,?)", [$owner['id'], $contact['ig_user_id'], $rule['id'], Util::now()]);
            self::log($owner, $contact, 'out', $gateText);
            Util::event($owner['id'], $ok ? 'gate' : 'error', $ok ? 'درخواست فالو ارسال شد' : 'ارسال پیام ناموفق بود', self::label($contact), $rule['id']);
            return;
        }
        self::deliver($owner, $acc, $contact, $rule, $recipient);
    }

    private static function checkGate(array $owner, array $acc, array $contact, string $ruleId): void
    {
        $rule = Db::one("SELECT * FROM rules WHERE id=? AND user_id=?", [$ruleId, $owner['id']]);
        if (!$rule) return;
        $recipient = ['id' => $contact['ig_user_id']];
        $profile = Instagram::profile($acc, $contact['ig_user_id']);
        if (empty($profile['is_user_follow_business'])) {
            $t = 'هنوز فالو ثبت نشده 🙂 پیج را فالو کن و دوباره دکمه را بزن.';
            self::sendButton($acc, $recipient, $t, 'فالو کردم ✅', 'GATE:' . $rule['id']);
            self::log($owner, $contact, 'out', $t);
            Util::event($owner['id'], 'follow_missing', 'فالو هنوز انجام نشده', self::label($contact), $rule['id']);
            return;
        }
        Db::run("UPDATE follow_gates SET done=? WHERE user_id=? AND igsid=? AND rule_id=? AND done IS NULL", [Util::now(), $owner['id'], $contact['ig_user_id'], $rule['id']]);
        Db::run("UPDATE contacts SET follows=1, handle=IF(handle='', ?, handle) WHERE id=?", [(string)($profile['username'] ?? ''), $contact['id']]);
        Util::event($owner['id'], 'follow_ok', 'فالو تأیید شد', self::label($contact), $rule['id']);
        self::deliver($owner, $acc, $contact, $rule, $recipient);
    }

    /** The reward: the rule's text and its file (image, video, voice or document). */
    private static function deliver(array $owner, array $acc, array $contact, array $rule, array $recipient): void
    {
        [$ok, $err] = Instagram::send($acc, $recipient, ['text' => $rule['response']]);
        self::log($owner, $contact, 'out', $rule['response']);
        // After a private reply the conversation is open; follow-ups go to the user id.
        $next = ['id' => $contact['ig_user_id']];
        if ($ok && $rule['file_id']) {
            $f = Db::one("SELECT * FROM files WHERE id=? AND user_id=?", [$rule['file_id'], $owner['id']]);
            if ($f) {
                $type = str_starts_with($f['type'], 'image/') ? 'image' : (str_starts_with($f['type'], 'video/') ? 'video' : (str_starts_with($f['type'], 'audio/') ? 'audio' : 'file'));
                $url = Config::get('app_url') . '/api/f/' . $f['public_token'] . '/' . rawurlencode($f['name']);
                [$ok, $err] = Instagram::send($acc, $next, ['attachment' => ['type' => $type, 'payload' => ['url' => $url]]]);
                self::log($owner, $contact, 'out', '📎 ' . $f['name']);
                if ($ok) Util::event($owner['id'], 'file_sent', 'فایل «' . $f['name'] . '» ارسال شد', self::label($contact), $rule['id']);
            }
        }
        Util::event($owner['id'], $ok ? 'reply_sent' : 'error', $ok ? 'پاسخ «' . $rule['name'] . '» ارسال شد' : 'ارسال ناموفق: ' . mb_substr($err, 0, 120), self::label($contact), $rule['id']);
    }

    /** Button template; falls back to quick replies, then plain text. */
    private static function sendButton(array $acc, array $recipient, string $text, string $title, string $payload): bool
    {
        [$ok] = Instagram::send($acc, $recipient, ['attachment' => ['type' => 'template', 'payload' => [
            'template_type' => 'button', 'text' => mb_substr($text, 0, 640),
            'buttons' => [['type' => 'postback', 'title' => $title, 'payload' => $payload]]]]]);
        if ($ok) return true;
        [$ok] = Instagram::send($acc, $recipient, ['text' => $text, 'quick_replies' => [['content_type' => 'text', 'title' => $title, 'payload' => $payload]]]);
        if ($ok) return true;
        [$ok] = Instagram::send($acc, $recipient, ['text' => $text . "\n\nبعد از فالو، بنویس: فالو کردم"]);
        return $ok;
    }
}
