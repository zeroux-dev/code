<?php
// Test helper: link the given user to a fake IG account and deliver a signed comment + follow postback.
require __DIR__ . '/../src/Core.php';
[$_, $email] = $argv;
$u = Db::one("SELECT id FROM users WHERE email=?", [$email]);
$rule = Db::one("SELECT id FROM rules WHERE user_id=? ORDER BY created DESC LIMIT 1", [$u['id']]);
Db::run("UPDATE users SET sub_expires=? WHERE id=?", [Util::now() + 30 * 86400000, $u['id']]);
Db::run("REPLACE INTO ig_accounts (user_id, ig_user_id, username, token, token_expires, connected) VALUES (?,?,?,?,?,?)", [$u['id'], '1785000000', 'repol.shop', Util::encrypt('T'), Util::now() + 5e9, Util::now()]);
$send = function (array $p) { $raw = json_encode($p, JSON_UNESCAPED_UNICODE);
  $ch = curl_init('http://localhost:8100/api/webhook/instagram');
  curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => 1, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, 'test-secret')]]);
  curl_exec($ch); usleep(300000); };
@unlink('/tmp/mock-follow');
foreach ([['IG-1', 'mahsa.art', 'آموزش رو میخوام 😍'], ['IG-2', 'reza_dev', 'سلام آموزش']] as $i => [$id, $un, $t]) {
  $send(['entry' => [['id' => '1785000000', 'changes' => [['field' => 'comments', 'value' => ['id' => "c-$i-" . time(), 'text' => $t, 'from' => ['id' => $id, 'username' => $un]]]]]]]);
}
touch('/tmp/mock-follow');
$send(['entry' => [['id' => '1785000000', 'messaging' => [['sender' => ['id' => 'IG-1'], 'recipient' => ['id' => '1785000000'], 'postback' => ['mid' => 'pb-' . time(), 'payload' => 'GATE:' . $rule['id']]]]]]]);
echo "ok\n";
