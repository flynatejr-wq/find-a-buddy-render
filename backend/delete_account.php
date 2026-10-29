<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$password = (string) ($input['password'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$userId]);

if (!password_verify($password, (string) $stmt->fetchColumn())) {
    http_response_code(403);
    echo json_encode(['error' => 'Password is not correct, so the account was not deleted.']);
    exit;
}

// Courses, profile, requests, messages, sessions and reset tokens are removed by ON DELETE CASCADE.
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

$_SESSION = [];
session_destroy();

echo json_encode(['success' => true]);
