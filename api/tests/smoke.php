<?php
// End-to-end API test against the local dev server (see README-backend.md).
declare(strict_types=1);
$base = 'http://localhost:8100/api';
$fails = 0; $n = 0;
function ok(bool $c, string $msg): void { global $fails, $n; $n++; echo ($c ? "  ✔ " : "  ✘ ") . $msg . "\n"; if (!$c) $fails++; }
function call(string $jar, string $method, string $path, ?array $body = null, array $extra = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Accept: application/json'];
    if ($body !== null || $method !== 'GET') { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_UNESCAPED_UNICODE)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => array_merge($h, $extra), CURLOPT_HEADER => false]);
    $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$s, json_decode((string)$raw, true), $raw];
}
function lastCode(string $email): string {
    $log = file_get_contents(sys_get_temp_dir() . '/repol-mail.log');
    preg_match_all('/TO: ' . preg_quote($email, '/') . '.*?کد: (\d{6})/su', $log, $m);
    return end($m[1]) ?: '';
}
@unlink(sys_get_temp_dir() . '/repol-mail.log'); @unlink('/tmp/mock-graph.log'); @unlink('/tmp/mock-follow');
$A = tempnam('/tmp', 'a'); $U = tempnam('/tmp', 'u'); $X = tempnam('/tmp', 'x');

echo "Setup & auth\n";
[$s, $j] = call($A, 'GET', '/status'); ok($j['setup'] === true, 'fresh install asks for setup');
[$s, $j] = call($A, 'POST', '/auth/setup', ['name' => 'مدیر', 'email' => 'admin@repol.test', 'password' => 'short', 'setupToken' => 'dev-setup']); ok($s === 400, 'short password rejected');
[$s, $j] = call($A, 'POST', '/auth/setup', ['name' => 'مدیر', 'email' => 'admin@repol.test', 'password' => 'correct horse battery', 'setupToken' => 'wrong']); ok($s === 403, 'wrong setup token rejected');
[$s, $j] = call($A, 'POST', '/auth/setup', ['name' => 'مدیر ریپل', 'email' => 'admin@repol.test', 'password' => 'correct horse battery', 'setupToken' => 'dev-setup']); ok($s === 200, 'admin created');
[$s, $j] = call($A, 'GET', '/status'); ok($j['setup'] === false && $j['authenticated'] === true, 'admin logged in');
[$s] = call($X, 'POST', '/auth/setup', ['name' => 'x', 'email' => 'x@x.test', 'password' => 'correct horse battery', 'setupToken' => 'dev-setup']); ok($s === 409, 'second setup blocked');
[$s] = call($X, 'POST', '/rules', ['name' => 'x'], ['Origin: https://evil.example']); ok($s === 403, 'cross-origin write blocked');
[$s] = call($X, 'GET', '/data'); ok($s === 401, '/data requires login');

echo "Sign-up with e-mail code\n";
[$s, $j] = call($U, 'POST', '/auth/register', ['name' => 'سارا', 'email' => 'sara@repol.test', 'password' => 'another long password']); ok($s === 200 && $j['verify'] === true, 'register sends a code');
$code = lastCode('sara@repol.test'); ok(strlen($code) === 6, 'code e-mailed');
[$s] = call($U, 'POST', '/auth/register/verify', ['email' => 'sara@repol.test', 'code' => '000000' === $code ? '111111' : '000000']); ok($s === 400, 'wrong code rejected');
[$s] = call($U, 'POST', '/auth/register/verify', ['email' => 'sara@repol.test', 'code' => strtr($code, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'])]); ok($s === 200, 'Persian-digit code accepted, account created');
[$s, $d] = call($U, 'GET', '/data'); ok($s === 200 && $d['owner']['email'] === 'sara@repol.test' && $d['owner']['role'] === 'user', '/data returns the new user');
ok(count($d['notifications']) === 1 && $d['subscription']['active'] === false, 'welcome notice, no subscription yet');
[$s] = call($U, 'POST', '/auth/register', ['name' => 'سارا', 'email' => 'sara@repol.test', 'password' => 'another long password']); ok($s === 409, 'duplicate e-mail refused');

echo "Login by password and by code\n";
[$s] = call($X, 'POST', '/auth/login', ['email' => 'sara@repol.test', 'password' => 'nope nope nope']); ok($s === 401, 'bad password');
[$s] = call($X, 'POST', '/auth/code', ['email' => 'nobody@repol.test']); ok($s === 200, 'code request does not reveal unknown e-mails');
[$s] = call($X, 'POST', '/auth/code', ['email' => 'sara@repol.test']); $c = lastCode('sara@repol.test');
[$s] = call($X, 'POST', '/auth/verify', ['email' => 'sara@repol.test', 'code' => $c]); ok($s === 200, 'login by e-mailed code');
[$s] = call($X, 'POST', '/auth/logout', []); [$s] = call($X, 'GET', '/data'); ok($s === 401, 'logout ends session');

echo "Scenarios, files, simulate\n";
$png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
[$s, $f] = call($U, 'POST', '/files', ['name' => 'آموزش.png', 'type' => 'image/png', 'content' => $png]); ok($s === 200 && isset($f['id']), 'file uploaded');
[$s] = call($U, 'POST', '/files', ['name' => 'x.php', 'type' => 'image/png', 'content' => base64_encode('<?php echo 1;')]); ok($s === 400, 'disguised script rejected by content sniffing');
[$s, $r] = call($U, 'POST', '/rules', ['name' => 'ارسال آموزش', 'trigger' => 'comment', 'keywords' => 'آموزش، شروع', 'match' => 'contains', 'response' => 'اینم آموزشت 🌱', 'followGate' => true, 'fileId' => $f['id'], 'commentReply' => 'دایرکتت رو چک کن 💌']);
ok($s === 200 && $r['followGate'] === true && $r['keywords'] === ['آموزش', 'شروع'], 'rule created with follow gate + file');
[$s, $j] = call($U, 'POST', '/simulate', ['trigger' => 'comment', 'text' => 'سلام آموزش رو میخوام']); ok(($j['match']['id'] ?? '') === $r['id'], 'simulator matches keyword');
[$s, $j] = call($U, 'POST', '/simulate', ['trigger' => 'dm', 'text' => 'آموزش']); ok($j['match'] === null, 'simulator respects trigger type');
[$s, $r2] = call($U, 'PUT', '/rules/' . $r['id'], ['name' => 'ارسال آموزش', 'trigger' => 'comment', 'keywords' => ['آموزش'], 'response' => 'اینم آموزشت 🌱', 'followGate' => true, 'fileId' => $f['id'], 'commentReply' => 'دایرکتت رو چک کن 💌', 'enabled' => true]);
ok($s === 200 && $r2['keywords'] === ['آموزش'], 'rule updated');
[$s] = call($A, 'PUT', '/rules/' . $r['id'], ['name' => 'hijack', 'trigger' => 'dm', 'keywords' => ['x'], 'response' => 'x']); ok($s === 404, "cannot edit someone else's rule");

echo "Billing: wallet top-up (manual), buy with wallet, grant\n";
[$s, $o] = call($U, 'POST', '/orders', ['kind' => 'subscription', 'planId' => 'start', 'method' => 'wallet', 'requestId' => 'r1']); ok($s === 402, 'wallet purchase blocked when balance is low');
[$s, $o] = call($U, 'POST', '/orders', ['kind' => 'topup', 'method' => 'rial', 'amount' => 10000000, 'requestId' => 'r2']); ok($s === 200 && $o['status'] === 'pending', 'rial top-up order created');
[$s, $o2] = call($U, 'POST', '/orders', ['kind' => 'topup', 'method' => 'rial', 'amount' => 10000000, 'requestId' => 'r2']); ok($o2['id'] === $o['id'], 'same requestId is idempotent');
[$s] = call($U, 'POST', "/orders/{$o['id']}/proof", ['proof' => 'کارت به کارت ۱۲۳۴']); ok($s === 200, 'payment proof saved');
[$s] = call($U, 'POST', "/admin/orders/{$o['id']}/review", ['status' => 'approved', 'confirmed' => true]); ok($s === 403, 'users cannot approve orders');
[$s] = call($A, 'POST', "/admin/orders/{$o['id']}/review", ['status' => 'approved']); ok($s === 400, 'approval needs explicit confirmation');
[$s] = call($A, 'POST', "/admin/orders/{$o['id']}/review", ['status' => 'approved', 'confirmed' => true, 'note' => 'دریافت شد']); ok($s === 200, 'admin approves');
[$s] = call($A, 'POST', "/admin/orders/{$o['id']}/review", ['status' => 'approved', 'confirmed' => true]); ok($s === 409, 'double approval impossible');
[$s, $d] = call($U, 'GET', '/data'); ok($d['wallet']['balance'] === 10000000 && count($d['wallet']['ledger']) === 1, 'wallet credited exactly once');
[$s, $o] = call($U, 'POST', '/orders', ['kind' => 'subscription', 'planId' => 'start', 'method' => 'wallet', 'requestId' => 'r3']); ok($s === 200 && $o['status'] === 'approved', 'plan bought with wallet → auto-activated');
[$s, $d] = call($U, 'GET', '/data'); ok($d['subscription']['active'] && $d['subscription']['daysRemaining'] === 30 && $d['wallet']['balance'] === 10000000 - 8490000, 'subscription active: 30 days, balance deducted');
$uid = $d['owner']['id'];
[$s] = call($A, 'POST', "/admin/users/$uid/subscription", ['days' => 10, 'reason' => 'هدیه']); [$s, $d] = call($U, 'GET', '/data'); ok($d['subscription']['daysRemaining'] === 40, 'gift days stack on top (40)');
[$s] = call($U, 'POST', '/orders', ['kind' => 'subscription', 'planId' => 'start', 'method' => 'zarinpal', 'requestId' => 'r4']); ok($s === 409, 'zarinpal refused until configured');
[$s] = call($U, 'POST', '/orders', ['kind' => 'subscription', 'planId' => 'start', 'method' => 'crypto', 'requestId' => 'r5']); ok($s === 409, 'crypto refused until enabled');

echo "Admin\n";
[$s, $a] = call($A, 'GET', '/admin/overview'); ok($s === 200 && count($a['users']) === 2 && count($a['orders']) === 2, 'overview lists users and orders');
[$s] = call($U, 'GET', '/admin/overview'); ok($s === 403, 'users cannot open admin overview');
[$s, $j] = call($A, 'POST', '/admin/announcements', ['title' => 'نسخه‌ی تازه', 'body' => 'امکانات جدید', 'type' => 'release', 'userId' => '']); ok($j['recipients'] === 2, 'announcement reaches everyone');
[$s, $b] = call($A, 'PUT', '/admin/business', array_merge($a['business'], ['cryptoAddress' => 'Tnot-valid'])); ok($s === 400, 'invalid TRON address rejected');
[$s, $b] = call($A, 'PUT', '/admin/business', array_merge($a['business'], ['cryptoAddress' => 'TBbC1YJDnyfwzVvuekbZnb2KuEwoPVDEes', 'cryptoEnabled' => true, 'trxPriceIRR' => 3500000])); ok($s === 200 && $b['cryptoEnabled'] === true, 'valid TRON address accepted, crypto on');
[$s, $o] = call($U, 'POST', '/orders', ['kind' => 'subscription', 'planId' => 'start', 'method' => 'crypto', 'requestId' => 'r6']); ok($s === 200, 'crypto invoice issued');
[$s, $d] = call($U, 'GET', '/data'); $co = array_values(array_filter($d['orders'], fn($x) => $x['id'] === $o['id']))[0];
ok($co['currency'] === 'TRX' && $co['amount'] > 2420000 && $co['amount'] < 2440000 * 1.01 + 10000, 'TRX amount = price / rate with unique tail (' . $co['amount'] / 1e6 . ' TRX)');
[$s] = call($U, 'POST', "/orders/{$o['id']}/verify", ['txid' => 'abc']); ok($s === 400, 'malformed TxID rejected');

echo "Instagram webhook → smart direct\n";
require __DIR__ . '/../src/Core.php';
Db::run("REPLACE INTO ig_accounts (user_id, ig_user_id, username, token, token_expires, connected) VALUES (?,?,?,?,?,?)", [$uid, '1784000000', 'sara.page', Util::encrypt('TEST-TOKEN'), Util::now() + 5e9, Util::now()]);
$hook = function (array $payload) {
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init('http://localhost:8100/api/webhook/instagram');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, 'test-secret')]]);
    $t = microtime(true); curl_exec($ch); $ms = (microtime(true) - $t) * 1000; $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    usleep(400000); // let the post-response processing finish
    return [$s, $ms];
};
$graph = fn() => array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", @file_get_contents('/tmp/mock-graph.log') ?: '')));
[$s] = call($X, 'GET', '/webhook/instagram?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42'); 
$ch = curl_init('http://localhost:8100/api/webhook/instagram?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42'); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); ok(curl_exec($ch) === '42', 'webhook verification handshake');
$ch = curl_init('http://localhost:8100/api/webhook/instagram'); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Hub-Signature-256: sha256=bad']]); curl_exec($ch); ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 403, 'unsigned webhook rejected');

$comment = ['object' => 'instagram', 'entry' => [['id' => '1784000000', 'time' => time(), 'changes' => [['field' => 'comments', 'value' => ['id' => 'cmt-1', 'text' => 'آموزش لطفا', 'from' => ['id' => 'IGSID-9', 'username' => 'new_fan'], 'media' => ['id' => 'media-1']]]]]]];
[$s, $ms] = $hook($comment); ok($s === 200, sprintf('comment webhook answered in %.0f ms', $ms));
$g = $graph();
ok((bool)array_filter($g, fn($x) => str_ends_with($x['p'], '/cmt-1/replies')), 'public reply posted under the comment');
$gate = array_values(array_filter($g, fn($x) => str_ends_with($x['p'], '/messages')));
ok(count($gate) === 1 && ($gate[0]['b']['recipient']['comment_id'] ?? '') === 'cmt-1' && ($gate[0]['b']['message']['attachment']['payload']['buttons'][0]['payload'] ?? '') === 'GATE:' . $r['id'], 'private reply with «فالو کردم» button (not following yet)');
[$s] = $hook($comment); ok(count(array_filter($graph(), fn($x) => str_ends_with($x['p'], '/messages'))) === 1, 'duplicate delivery ignored');

$press = ['object' => 'instagram', 'entry' => [['id' => '1784000000', 'messaging' => [['sender' => ['id' => 'IGSID-9'], 'recipient' => ['id' => '1784000000'], 'timestamp' => time(), 'postback' => ['mid' => 'pb-1', 'title' => 'فالو کردم', 'payload' => 'GATE:' . $r['id']]]]]]];
$hook($press);
$last = array_values(array_filter($graph(), fn($x) => str_ends_with($x['p'], '/messages')));
ok(count($last) === 2 && str_contains($last[1]['b']['message']['attachment']['payload']['text'] ?? '', 'هنوز فالو'), 'button pressed without following → reminder');
touch('/tmp/mock-follow');
$press['entry'][0]['messaging'][0]['postback']['mid'] = 'pb-2';
$hook($press);
$last = array_values(array_filter($graph(), fn($x) => str_ends_with($x['p'], '/messages')));
ok(($last[2]['b']['message']['text'] ?? '') === 'اینم آموزشت 🌱', 'after following → reward text sent');
ok(($last[3]['b']['message']['attachment']['type'] ?? '') === 'image' && str_contains($last[3]['b']['message']['attachment']['payload']['url'] ?? '', '/api/f/'), 'then the file is sent as an image attachment');
$url = $last[3]['b']['message']['attachment']['payload']['url'];
$ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $bin = curl_exec($ch); ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && strlen($bin) > 60, 'public file link serves the attachment');

$dm = ['object' => 'instagram', 'entry' => [['id' => '1784000000', 'messaging' => [['sender' => ['id' => 'IGSID-9'], 'recipient' => ['id' => '1784000000'], 'message' => ['mid' => 'mid-echo', 'text' => 'x', 'is_echo' => true]]]]]];
$before = count($graph()); $hook($dm); ok(count($graph()) === $before, 'echo messages ignored');

[$s, $d] = call($U, 'GET', '/data');
$types = array_column($d['events'], 'type');
ok(in_array('comment', $types) && in_array('gate', $types) && in_array('follow_ok', $types) && in_array('file_sent', $types) && in_array('reply_sent', $types), 'events logged: comment → gate → follow_ok → reply → file');
ok($d['stats']['week']['conversations'] >= 1 && $d['stats']['week']['replies'] >= 1 && $d['stats']['contacts'] === 1, 'dashboard stats are real');
ok(count($d['conversations']) === 1 && count($d['conversations'][0]['messages']) >= 4 && $d['conversations'][0]['follows'] === true, 'inbox conversation recorded, contact marked as follower');
ok($d['connections']['instagram'] === true && $d['instagramAccount']['username'] === 'sara.page', 'connection status reported');

echo "Expired subscription stops the bot\n";
Db::run("UPDATE users SET sub_expires=? WHERE id=?", [Util::now() - 1000, $uid]);
$c2 = $comment; $c2['entry'][0]['changes'][0]['value']['id'] = 'cmt-2';
$before = count($graph()); $hook($c2); ok(count($graph()) === $before, 'no messages sent without an active subscription');

echo "\n" . ($fails ? "FAILED $fails / $n" : "ALL $n CHECKS PASSED") . "\n";
exit($fails ? 1 : 0);
