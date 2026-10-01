<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$key = $_GET['key'] ?? '';
if ($key !== 'findabuddy-wipe-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = get_db();
$pdo->exec('DELETE FROM user_courses');
$pdo->exec('DELETE FROM users');

echo json_encode(['success' => true]);
