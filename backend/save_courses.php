<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/availability.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo = get_db();

if (!empty($input['remove_course_id'])) {
    $stmt = $pdo->prepare('DELETE FROM user_courses WHERE user_id = ? AND course_id = ?');
    $stmt->execute([$userId, (int) $input['remove_course_id']]);
}

if (isset($input['courses']) && is_array($input['courses'])) {
    foreach ($input['courses'] as $course) {
        $code = trim($course['course_code'] ?? '');
        $section = trim($course['section'] ?? '');
        $name = trim($course['course_name'] ?? '');
        if ($code === '') {
            continue;
        }

        $nullSafeEquals = db_is_postgres() ? 'section IS NOT DISTINCT FROM ?' : 'section <=> ?';
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ? AND $nullSafeEquals");
        $stmt->execute([$code, $section !== '' ? $section : null]);
        $courseId = $stmt->fetchColumn();

        if (!$courseId) {
            $stmt = $pdo->prepare('INSERT INTO courses (course_code, section, course_name) VALUES (?, ?, ?)');
            $stmt->execute([$code, $section !== '' ? $section : null, $name]);
            $courseId = $pdo->lastInsertId();
        }

        $insertUserCourse = db_is_postgres()
            ? 'INSERT INTO user_courses (user_id, course_id) VALUES (?, ?) ON CONFLICT (user_id, course_id) DO NOTHING'
            : 'INSERT IGNORE INTO user_courses (user_id, course_id) VALUES (?, ?)';
        $stmt = $pdo->prepare($insertUserCourse);
        $stmt->execute([$userId, $courseId]);
    }
}

if (array_key_exists('preferred_locations', $input) || array_key_exists('availability', $input)) {
    $locations = trim($input['preferred_locations'] ?? '');
    $availability = encode_availability($input['availability'] ?? []);

    $stmt = $pdo->prepare('UPDATE users SET preferred_locations = ?, availability = ? WHERE id = ?');
    $stmt->execute([$locations, $availability, $userId]);
}

echo json_encode(['success' => true]);
