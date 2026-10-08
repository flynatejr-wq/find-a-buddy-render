<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

if (($_GET['key'] ?? '') !== 'findabuddy-acctcheck-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$stmt = get_db()->prepare("DELETE FROM users WHERE email LIKE 'acctcheck%@student.savannahstate.edu'");
$stmt->execute();

echo json_encode(['deleted' => $stmt->rowCount()]);
