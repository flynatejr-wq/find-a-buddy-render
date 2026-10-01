<?php

require_once __DIR__ . '/db.php';

// Simple shared-secret protection -- this is a read-only demo view, not a
// real admin panel with proper authentication.
$key = $_GET['key'] ?? '';
if ($key !== 'findabuddy-admin-2026') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = get_db();

$users = $pdo->query('SELECT id, email, first_name, last_name, preferred_locations, availability, created_at FROM users ORDER BY id')->fetchAll();
$courses = $pdo->query('SELECT id, course_code, section, course_name FROM courses ORDER BY id')->fetchAll();
$userCourses = $pdo->query('
    SELECT uc.id, u.email, u.first_name, u.last_name, c.course_code, c.course_name
    FROM user_courses uc
    JOIN users u ON u.id = uc.user_id
    JOIN courses c ON c.id = uc.course_id
    ORDER BY uc.id
')->fetchAll();

function e($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Database View — Find a Buddy</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f2ede1; color: #171310; margin: 0; padding: 2rem; }
  h1 { color: #0a3a8c; }
  h2 { color: #0a3a8c; border-bottom: 2px solid #ff7a1a; padding-bottom: 0.3rem; margin-top: 2.5rem; }
  table { border-collapse: collapse; width: 100%; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
  th, td { border: 1px solid #ddd4bf; padding: 0.6rem 0.9rem; text-align: left; font-size: 0.9rem; }
  th { background: #0a3a8c; color: white; }
  tr:nth-child(even) { background: #faf7f0; }
  .empty { color: #7a7568; font-style: italic; padding: 1rem; }
  .count { color: #7a7568; font-size: 0.85rem; font-weight: normal; }
</style>
</head>
<body>
  <h1>Find a Buddy — Live Database View</h1>
  <p>Read-only view of the real tables in the production database. No passwords are shown.</p>

  <h2>users <span class="count">(<?= count($users) ?> rows)</span></h2>
  <?php if ($users): ?>
  <table>
    <tr><th>id</th><th>email</th><th>first_name</th><th>last_name</th><th>preferred_locations</th><th>availability</th><th>created_at</th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['id']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e($u['first_name']) ?></td>
      <td><?= e($u['last_name']) ?></td>
      <td><?= e($u['preferred_locations']) ?></td>
      <td><?= e($u['availability']) ?></td>
      <td><?= e($u['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="empty">No users yet.</p>
  <?php endif; ?>

  <h2>courses <span class="count">(<?= count($courses) ?> rows)</span></h2>
  <?php if ($courses): ?>
  <table>
    <tr><th>id</th><th>course_code</th><th>section</th><th>course_name</th></tr>
    <?php foreach ($courses as $c): ?>
    <tr>
      <td><?= e($c['id']) ?></td>
      <td><?= e($c['course_code']) ?></td>
      <td><?= e($c['section']) ?></td>
      <td><?= e($c['course_name']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="empty">No courses yet.</p>
  <?php endif; ?>

  <h2>user_courses (joined) <span class="count">(<?= count($userCourses) ?> rows)</span></h2>
  <?php if ($userCourses): ?>
  <table>
    <tr><th>id</th><th>student</th><th>email</th><th>course_code</th><th>course_name</th></tr>
    <?php foreach ($userCourses as $uc): ?>
    <tr>
      <td><?= e($uc['id']) ?></td>
      <td><?= e($uc['first_name']) ?> <?= e($uc['last_name']) ?></td>
      <td><?= e($uc['email']) ?></td>
      <td><?= e($uc['course_code']) ?></td>
      <td><?= e($uc['course_name']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="empty">No course enrollments yet.</p>
  <?php endif; ?>
</body>
</html>
