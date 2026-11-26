<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/wrapup.php';

$userId = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$pdo = get_db();

$stmt = $pdo->prepare('SELECT course_id FROM user_courses WHERE user_id = ?');
$stmt->execute([$userId]);
$current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

[$clean, $errors] = clean_wrapup_input(is_array($input) ? $input : [], $current);
if ($errors) {
    http_response_code(422);
    echo json_encode(['errors' => $errors]);
    exit;
}

echo json_encode(['success' => true] + apply_wrapup($pdo, $userId, $clean));
