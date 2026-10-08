<?php

header('Content-Type: application/json');
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/validation.php';

session_start();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = normalize_email($input['email'] ?? '');
$password = $input['password'] ?? '';

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id, password_hash, first_name, last_name FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['first_name'] = $user['first_name'];

echo json_encode([
    'success' => true,
    'user' => ['id' => $user['id'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name']],
]);
