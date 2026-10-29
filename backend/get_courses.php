<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/grades.php';

$userId = require_login();
$pdo = get_db();

if (isset($_GET['q'])) {
    $q = '%' . trim($_GET['q']) . '%';
    $stmt = $pdo->prepare('SELECT id, course_code, section, course_name FROM courses WHERE course_code LIKE ? OR course_name LIKE ? LIMIT 10');
    $stmt->execute([$q, $q]);
    echo json_encode(['courses' => $stmt->fetchAll()]);
    exit;
}

ensure_standing_tables($pdo);

// Courses I am taking now, with how I am doing in each.
$stmt = $pdo->prepare('
    SELECT c.id, c.course_code, c.section, c.course_name, p.status AS progress
    FROM user_courses uc
    JOIN courses c ON c.id = uc.course_id
    LEFT JOIN course_progress p ON p.user_id = uc.user_id AND p.course_id = uc.course_id
    WHERE uc.user_id = ?
    ORDER BY c.course_code
');
$stmt->execute([$userId]);
$courses = $stmt->fetchAll();

// Courses I have finished, with my grade (only ever shown to me).
$stmt = $pdo->prepare('
    SELECT c.id, c.course_code, c.section, c.course_name, g.grade
    FROM course_grades g
    JOIN courses c ON c.id = g.course_id
    WHERE g.user_id = ?
    ORDER BY c.course_code
');
$stmt->execute([$userId]);
$completed = array_map(function ($row) {
    $row['passed'] = grade_is_passing($row['grade']);
    return $row;
}, $stmt->fetchAll());

echo json_encode(['courses' => $courses, 'completed' => $completed]);
