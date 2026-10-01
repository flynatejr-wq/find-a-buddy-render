<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$secret = $_GET['key'] ?? '';
if ($secret !== 'findabuddy-cleanup-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = get_db();
$pdo->exec("DELETE FROM user_courses WHERE user_id IN (SELECT id FROM users WHERE email = 'renderfinal@savannahstate.edu')");
$pdo->exec("DELETE FROM users WHERE email = 'renderfinal@savannahstate.edu'");
$pdo->exec("DELETE FROM courses WHERE course_code = 'TEST 1000'");

echo json_encode(['success' => true]);
