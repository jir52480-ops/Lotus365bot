<?php
define('BOT_LIB_ONLY', true);
require_once __DIR__ . '/bot.php';

ignore_user_abort(true);
set_time_limit(0);

$rawPayload = null;
$token = '';

if (PHP_SAPI === 'cli') {
    $b64 = $argv[1] ?? '';
    $token = $argv[2] ?? '';
    $rawPayload = base64_decode($b64, true);
} else {
    header('Content-Type: application/json');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }

    $rawPayload = file_get_contents('php://input');
    $token = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? '';
}

if (!$rawPayload || !$token) exit;

$expected = hash_hmac('sha256', $rawPayload, WORKER_SECRET);
if (!hash_equals($expected, $token)) exit;

$data = json_decode($rawPayload, true);
if (!is_array($data)) exit;

$uid = (int)($data['uid'] ?? 0);
$chat_id = (int)($data['chat_id'] ?? 0);
$urls = (array)($data['urls'] ?? []);
$scan_msg_id = $data['scan_msg_id'] ?? null;

if ($uid > 0 && $chat_id > 0 && !empty($urls)) {
    runAutoFlow($uid, $chat_id, $urls, $scan_msg_id);
}
