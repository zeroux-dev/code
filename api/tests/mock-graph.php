<?php
// Fake graph.instagram.com for local tests. Records every call in /tmp/mock-graph.log.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents('/tmp/mock-graph.log', json_encode(['m' => $_SERVER['REQUEST_METHOD'], 'p' => $path, 'q' => $_GET, 'b' => json_decode($body, true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (preg_match('#/messages$#', $path)) { echo json_encode(['recipient_id' => '1', 'message_id' => 'm_' . bin2hex(random_bytes(4))]); exit; }
if (preg_match('#/replies$#', $path)) { echo json_encode(['id' => 'r1']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode(['username' => 'follower_test', 'name' => 'Test', 'is_user_follow_business' => file_exists('/tmp/mock-follow')]); exit; }
echo json_encode(['success' => true]);
