<?php

// Read-only admin dashboard. Protected by an ADMIN_KEY that lives in the
// server's environment (Render dashboard) or backend/config.php, never in
// the code, so it can't be read from the public repo. If no key is set the
// page is disabled.

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/profile.php';
require_once __DIR__ . '/lib/reset.php';

session_start();

function admin_key(): string
{
    $key = getenv('ADMIN_KEY');
    if ($key) {
        return $key;
    }
    $configFile = __DIR__ . '/config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
        return (string) ($config['admin_key'] ?? '');
    }
    return '';
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$key = admin_key();
$loginError = false;

if ($key === '') {
    http_response_code(403);
    echo 'Admin view is disabled: no ADMIN_KEY is configured on the server.';
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    header('Location: admin_view.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($key, (string) ($_POST['key'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        header('Location: admin_view.php');
        exit;
    }
    sleep(1); // slow down guessing
    $loginError = true;
}

if (empty($_SESSION['is_admin'])) {
    http_response_code($loginError ? 401 : 200);
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin sign in | Find a Buddy</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f2ede1; color: #171310; display: grid; place-items: center; min-height: 100vh; margin: 0; }
  form { background: #fff; border: 1px solid #ddd4bf; border-radius: 12px; padding: 2rem; width: min(360px, 90vw); }
  h1 { font-size: 1.2rem; color: #0a3a8c; margin: 0 0 1rem; }
  input { width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #ddd4bf; border-radius: 8px; font-size: 1rem; }
  button { margin-top: 0.8rem; width: 100%; padding: 0.7rem; border: 0; border-radius: 8px; background: #ff7a1a; color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; }
  .err { color: #b3261e; font-size: 0.88rem; margin: 0 0 0.8rem; }
</style></head>
<body>
  <form method="post">
    <h1>Find a Buddy admin</h1>
    <?php if ($loginError): ?><p class="err">Wrong key.</p><?php endif; ?>
    <input type="password" name="key" placeholder="Admin key" autocomplete="current-password" autofocus required>
    <button type="submit">View database</button>
  </form>
</body></html>
    <?php
    exit;
}

// ---------- Authenticated ----------
$pdo = get_db();
ensure_profiles_table($pdo);
ensure_resets_table($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// The one write this page can do: create a one-time password reset link for a student
// who cannot receive reset emails. The link is shown once and only its hash is stored.
$resetLink = null;
$resetFor = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_link') {
    if (hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = ?');
        $stmt->execute([(int) ($_POST['user_id'] ?? 0)]);
        if ($target = $stmt->fetch()) {
            $base = app_base_url() !== ''
                ? app_base_url()
                : ((($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) ? 'https' : 'http'))) . '://' . $_SERVER['HTTP_HOST']);
            $resetLink = reset_link($base, create_reset_token($pdo, (int) $target['id']));
            $resetFor = $target['email'];
        }
    }
}

$users = $pdo->query('
    SELECT u.id, u.email, u.first_name, u.last_name, u.created_at,
           (SELECT COUNT(*) FROM user_courses uc WHERE uc.user_id = u.id) AS course_count,
           (SELECT COUNT(*) FROM profiles p WHERE p.user_id = u.id) AS has_profile
    FROM users u ORDER BY u.id
')->fetchAll();

$profiles = $pdo->query('
    SELECT p.user_id, u.first_name, u.last_name, p.major, p.year, p.study_times, p.study_style, p.study_place, p.help_text, p.updated_at
    FROM profiles p JOIN users u ON u.id = p.user_id ORDER BY p.user_id
')->fetchAll();

$courses = $pdo->query('
    SELECT c.id, c.course_code, c.section, c.course_name,
           (SELECT COUNT(*) FROM user_courses uc WHERE uc.course_id = c.id) AS students
    FROM courses c ORDER BY c.course_code
')->fetchAll();

$enrollments = $pdo->query('
    SELECT uc.id, u.first_name, u.last_name, u.email, c.course_code, c.course_name
    FROM user_courses uc
    JOIN users u ON u.id = uc.user_id
    JOIN courses c ON c.id = uc.course_id
    ORDER BY uc.id
')->fetchAll();

$requests = $pdo->query('
    SELECT r.id, s.first_name AS sender_first, s.last_name AS sender_last,
           t.first_name AS receiver_first, t.last_name AS receiver_last,
           c.course_code, r.status, r.created_at
    FROM buddy_requests r
    JOIN users s ON s.id = r.sender_id
    JOIN users t ON t.id = r.receiver_id
    LEFT JOIN courses c ON c.id = r.course_id
    ORDER BY r.id
')->fetchAll();

$weekAgo = time() - 7 * 86400;
$newThisWeek = count(array_filter($users, fn($u) => strtotime((string) $u['created_at']) >= $weekAgo));
$withProfile = count(array_filter($users, fn($u) => (int) $u['has_profile'] > 0));
$withCourses = count(array_filter($users, fn($u) => (int) $u['course_count'] > 0));
$byStatus = ['pending' => 0, 'accepted' => 0, 'declined' => 0];
foreach ($requests as $r) {
    $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
}
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'PostgreSQL' : 'MySQL';

function render_table(string $id, string $title, array $columns, array $rows, string $emptyText): void
{
    echo '<section class="block" id="' . e($id) . '"><div class="block-head"><h2>' . e($title)
        . ' <span class="count">' . count($rows) . ' row' . (count($rows) === 1 ? '' : 's') . '</span></h2>'
        . '<input type="search" class="filter" placeholder="Filter…" data-table="' . e($id) . '-table" aria-label="Filter ' . e($title) . '"></div>';
    if (!$rows) {
        echo '<p class="empty">' . e($emptyText) . '</p></section>';
        return;
    }
    echo '<div class="scroll"><table id="' . e($id) . '-table"><thead><tr>';
    foreach ($columns as $label) {
        echo '<th>' . e($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($columns) as $field) {
            echo '<td>' . e($row[$field] ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
}

foreach ($users as &$u) {
    $u['name'] = trim($u['first_name'] . ' ' . $u['last_name']);
    $u['profile'] = (int) $u['has_profile'] ? 'yes' : 'no';
}
unset($u);
foreach ($profiles as &$p) {
    $p['name'] = trim($p['first_name'] . ' ' . $p['last_name']);
}
unset($p);
foreach ($enrollments as &$en) {
    $en['name'] = trim($en['first_name'] . ' ' . $en['last_name']);
}
unset($en);
foreach ($requests as &$rq) {
    $rq['from'] = trim($rq['sender_first'] . ' ' . $rq['sender_last']);
    $rq['to'] = trim($rq['receiver_first'] . ' ' . $rq['receiver_last']);
}
unset($rq);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin | Find a Buddy</title>
<style>
  :root { --navy: #0a3a8c; --orange: #ff7a1a; --paper: #f2ede1; --card: #fff; --line: #ddd4bf; --ink: #171310; --muted: #7a7568; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--paper); color: var(--ink); }
  header { background: var(--navy); color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem; }
  header h1 { margin: 0; font-size: 1.15rem; letter-spacing: 0.02em; }
  header h1 span { color: var(--orange); }
  header nav a { color: #fff; text-decoration: none; font-size: 0.85rem; margin-left: 1rem; opacity: 0.85; }
  header nav a:hover { opacity: 1; text-decoration: underline; }
  main { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
  .note { color: var(--muted); font-size: 0.88rem; margin: 0 0 1.2rem; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.8rem; margin-bottom: 2rem; }
  .stat { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 0.9rem 1rem; }
  .stat b { display: block; font-size: 1.7rem; color: var(--navy); line-height: 1.1; }
  .stat span { font-size: 0.8rem; color: var(--muted); }
  .block { margin-bottom: 2.2rem; }
  .block-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; border-bottom: 2px solid var(--orange); margin-bottom: 0.7rem; padding-bottom: 0.3rem; }
  h2 { font-size: 1.05rem; color: var(--navy); margin: 0; }
  .count { font-size: 0.78rem; font-weight: 400; color: var(--muted); margin-left: 0.4rem; }
  .filter { padding: 0.4rem 0.7rem; border: 1px solid var(--line); border-radius: 8px; font-size: 0.85rem; min-width: 180px; }
  .scroll { overflow-x: auto; background: var(--card); border: 1px solid var(--line); border-radius: 10px; }
  table { border-collapse: collapse; width: 100%; font-size: 0.86rem; }
  th, td { text-align: left; padding: 0.55rem 0.8rem; border-bottom: 1px solid var(--line); white-space: nowrap; }
  td:last-child, th:last-child { white-space: normal; }
  th { background: #f7f3ea; color: var(--navy); font-weight: 600; position: sticky; top: 0; }
  tbody tr:hover { background: #fffaf2; }
  .empty { color: var(--muted); font-style: italic; margin: 0.6rem 0; }
  .banner { background: #fff4e5; border: 1px solid var(--orange); border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 1.5rem; font-size: 0.9rem; }
  .banner input { display: block; width: 100%; margin-top: 0.5rem; padding: 0.55rem; border: 1px solid var(--line); border-radius: 8px; font-family: ui-monospace, Consolas, monospace; font-size: 0.8rem; }
  .reset-form { display: flex; gap: 0.6rem; flex-wrap: wrap; }
  .reset-form select { flex: 1; min-width: 220px; padding: 0.55rem; border: 1px solid var(--line); border-radius: 8px; font-size: 0.9rem; }
  .reset-form button { padding: 0.55rem 1rem; border: 0; border-radius: 8px; background: var(--orange); color: #fff; font-weight: 600; cursor: pointer; }
  @media (max-width: 600px) { main { padding: 1rem; } }
</style>
</head>
<body>
<header>
  <h1>FIND A <span>BUDDY</span> · Admin</h1>
  <nav>
    <a href="#users">Users</a><a href="#profiles">Profiles</a><a href="#courses">Courses</a>
    <a href="#enrollments">Enrollments</a><a href="#requests">Requests</a>
    <a href="?logout=1">Sign out</a>
  </nav>
</header>
<main>
  <p class="note">View of the live <?= e($driver) ?> database. Passwords are never shown or even queried. The only action here is creating a password reset link.</p>

  <?php if ($resetLink): ?>
  <div class="banner">
    <strong>Reset link for <?= e($resetFor) ?></strong> (works once, expires in 1 hour). Send it to the student yourself:
    <input type="text" readonly value="<?= e($resetLink) ?>" onclick="this.select()" aria-label="Reset link">
  </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><b><?= count($users) ?></b><span>Total users</span></div>
    <div class="stat"><b><?= $newThisWeek ?></b><span>New in last 7 days</span></div>
    <div class="stat"><b><?= $withProfile ?></b><span>Profiles completed</span></div>
    <div class="stat"><b><?= $withCourses ?></b><span>Users with courses</span></div>
    <div class="stat"><b><?= count($courses) ?></b><span>Courses</span></div>
    <div class="stat"><b><?= count($requests) ?></b><span>Buddy requests</span></div>
    <div class="stat"><b><?= $byStatus['pending'] ?>/<?= $byStatus['accepted'] ?>/<?= $byStatus['declined'] ?></b><span>Pending / accepted / declined</span></div>
  </div>

  <?php
  render_table('users', 'Users', [
      'id' => 'id', 'name' => 'Name', 'email' => 'Email', 'course_count' => 'Courses',
      'profile' => 'Profile', 'created_at' => 'Joined',
  ], $users, 'No users yet.');

  ?>
  <section class="block" id="reset">
    <div class="block-head"><h2>Create a password reset link</h2></div>
    <form method="post" class="reset-form">
      <input type="hidden" name="action" value="reset_link">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <select name="user_id" aria-label="Student" required>
        <?php foreach ($users as $u): ?>
        <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Create link</button>
    </form>
  </section>
  <?php
  render_table('profiles', 'Profiles', [
      'user_id' => 'user id', 'name' => 'Name', 'major' => 'Major', 'year' => 'Year',
      'study_times' => 'Study times', 'study_style' => 'Style', 'study_place' => 'Place',
      'updated_at' => 'Updated', 'help_text' => 'Wants help with',
  ], $profiles, 'No profiles yet.');

  render_table('courses', 'Courses', [
      'id' => 'id', 'course_code' => 'Code', 'section' => 'Section', 'students' => 'Students', 'course_name' => 'Name',
  ], $courses, 'No courses yet.');

  render_table('enrollments', 'Enrollments (who is in which course)', [
      'id' => 'id', 'name' => 'Student', 'email' => 'Email', 'course_code' => 'Course', 'course_name' => 'Course name',
  ], $enrollments, 'No course enrollments yet.');

  render_table('requests', 'Buddy requests', [
      'id' => 'id', 'from' => 'From', 'to' => 'To', 'course_code' => 'Course', 'status' => 'Status', 'created_at' => 'Sent',
  ], $requests, 'No buddy requests yet.');
  ?>
</main>
<script>
  document.querySelectorAll('.filter').forEach(function (input) {
    input.addEventListener('input', function () {
      var table = document.getElementById(input.dataset.table);
      if (!table) return;
      var q = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
      });
    });
  });
</script>
</body>
</html>
