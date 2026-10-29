<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/account.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$first = clean_person_name($input['first_name'] ?? null);
$last = clean_person_name($input['last_name'] ?? null);

if ($first === null || $last === null) {
    http_response_code(422);
    echo json_encode(['error' => 'First and last name are required (up to 100 characters each).']);
    exit;
}

get_db()->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?')->execute([$first, $last, $userId]);
$_SESSION['first_name'] = $first;

echo json_encode(['success' => true, 'user' => ['first_name' => $first, 'last_name' => $last]]);
