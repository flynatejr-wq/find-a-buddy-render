<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/grades.php';

/** "csci   2231k" becomes "CSCI 2231K", so the same course typed differently is found as one. */
function normalize_course_code(string $code): string
{
    return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $code)));
}

/** Finds a course by code and section, creating it if it does not exist. Returns its id. */
function find_or_create_course(PDO $pdo, string $code, string $section, string $name): int
{
    $nullSafeEquals = db_is_postgres() ? 'section IS NOT DISTINCT FROM ?' : 'section <=> ?';
    $stmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ? AND $nullSafeEquals");
    $stmt->execute([$code, $section !== '' ? $section : null]);
    $courseId = $stmt->fetchColumn();

    if (!$courseId) {
        $stmt = $pdo->prepare('INSERT INTO courses (course_code, section, course_name) VALUES (?, ?, ?)');
        $stmt->execute([$code, $section !== '' ? $section : null, $name]);
        $courseId = $pdo->lastInsertId();
    }
    return (int) $courseId;
}

/**
 * The course that connects two students, or null if they have no connection.
 * First choice is a course they are both taking now. Otherwise it is a course one is
 * failing and the other has passed (a tutoring link).
 */
function connection_course_id(PDO $pdo, int $a, int $b): ?int
{
    $stmt = $pdo->prepare('
        SELECT x.course_id FROM user_courses x
        JOIN user_courses y ON y.course_id = x.course_id
        WHERE x.user_id = ? AND y.user_id = ?
        ORDER BY x.course_id LIMIT 1
    ');
    $stmt->execute([$a, $b]);
    if ($id = $stmt->fetchColumn()) {
        return (int) $id;
    }

    ensure_standing_tables($pdo);
    $passed = "'" . implode("','", PASSING_GRADES) . "'";
    $stmt = $pdo->prepare("
        SELECT p.course_id FROM course_progress p
        JOIN user_courses uc ON uc.user_id = p.user_id AND uc.course_id = p.course_id
        JOIN course_grades g ON g.course_id = p.course_id AND g.user_id = ?
        WHERE p.user_id = ? AND p.status = 'failing' AND g.grade IN ($passed) AND g.available_to_help = 1
        ORDER BY p.course_id LIMIT 1
    ");
    foreach ([[$b, $a], [$a, $b]] as [$helper, $learner]) {
        $stmt->execute([$helper, $learner]);
        if ($id = $stmt->fetchColumn()) {
            return (int) $id;
        }
    }
    return null;
}
