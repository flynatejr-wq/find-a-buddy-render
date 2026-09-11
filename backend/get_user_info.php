<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

$userId = require_login();
$pdo = get_db();
$stmt = $pdo->prepare('SELECT id, email, first_name, last_name, preferred_locations, availability FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

echo json_encode(['user' => $user]);
