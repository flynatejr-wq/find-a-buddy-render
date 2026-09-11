<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/validation.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$errors = validate_registration_input($input);

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['errors' => $errors]);
    exit;
}

$pdo = get_db();
$email = trim($input['email']);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['errors' => ['email' => 'An account with this email already exists.']]);
    exit;
}

$hash = password_hash($input['password'], PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)');
$stmt->execute([$email, $hash, trim($input['first_name']), trim($input['last_name'])]);

echo json_encode(['success' => true]);
