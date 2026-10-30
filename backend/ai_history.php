<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/ai_tools.php';

$userId = require_login();
$pdo = get_db();

$limits = ai_limits();
$usage = ai_usage_today($pdo, $userId);

$messages = array_map(
    fn($m) => ['sender' => $m['sender'], 'message' => $m['message']],
    ai_recent_messages($pdo, $userId, 30)
);

echo json_encode([
    'configured' => ai_is_configured(),
    'messages' => $messages,
    'remaining_today' => max(0, $limits['user'] - $usage['user']),
    'daily_limit' => $limits['user'],
]);
