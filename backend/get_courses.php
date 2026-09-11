<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

$userId = require_login();
$pdo = get_db();

if (isset($_GET['q'])) {
    $q = '%' . trim($_GET['q']) . '%';
    $stmt = $pdo->prepare('SELECT id, course_code, section, course_name FROM courses WHERE course_code LIKE ? OR course_name LIKE ? LIMIT 10');
    $stmt->execute([$q, $q]);
    echo json_encode(['courses' => $stmt->fetchAll()]);
    exit;
}

$stmt = $pdo->prepare('
    SELECT c.id, c.course_code, c.section, c.course_name
    FROM user_courses uc
    JOIN courses c ON c.id = uc.course_id
    WHERE uc.user_id = ?
    ORDER BY c.course_code
');
$stmt->execute([$userId]);
echo json_encode(['courses' => $stmt->fetchAll()]);
