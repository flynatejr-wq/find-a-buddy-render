# Find a Buddy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the "Find a Buddy" web app end-to-end per `docs/superpowers/specs/2026-09-10-find-a-buddy-design.md`: SSU students register, post their courses, find/request study buddies, and chat with an AI mascot.

**Architecture:** Vanilla HTML/CSS/JS frontend talking to PHP 8 JSON endpoints over `fetch`, PDO/MySQL for storage, PHP sessions for auth. Pure business logic (validation, availability matching, course matching) lives in small `backend/lib/*.php` function files so it can be unit-tested with PHPUnit independent of HTTP/DB; endpoint files are thin glue that call those functions plus PDO queries and are verified manually via curl/browser against a real local MySQL DB (Laragon).

**Tech Stack:** PHP 8+, MySQL 8, PDO, vanilla JS (`fetch`), PHPUnit 10 (dev-only, via Composer), Laragon for local Apache+PHP+MySQL.

**Prerequisite:** Laragon installed at `C:\laragon` with Apache and MySQL running, project folder placed at `C:\laragon\www\find-a-buddy` (Laragon serves everything under `www\` at `http://<foldername>.test` automatically). If you developed the repo elsewhere (e.g. `C:\Users\flyna\find-a-buddy`), move or symlink it into `C:\laragon\www\` before running the manual browser-verification steps in Tasks 5+.

---

### Task 0: Project scaffolding, Composer, PHPUnit

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.gitignore`
- Create: `backend/lib/.gitkeep`
- Create: `tests/.gitkeep`

- [ ] **Step 1: Create the directory skeleton**

```bash
mkdir -p backend/lib tests frontend static uploads
```

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "flynatejr/find-a-buddy",
    "require-dev": {
        "phpunit/phpunit": "^10"
    }
}
```

- [ ] **Step 3: Install PHPUnit**

Run: `composer require --dev phpunit/phpunit --no-interaction`
Expected: Composer resolves and installs `phpunit/phpunit` into `vendor/`, creates/updates `composer.lock`.

If `composer` is not found, install it from inside Laragon: right-click the Laragon tray icon → **Tools → Quick add → Composer**, then retry the command in a new terminal.

- [ ] **Step 4: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: Create `.gitignore`**

```
/vendor/
/backend/config.php
.DS_Store
Thumbs.db
```

- [ ] **Step 6: Verify PHPUnit runs with zero tests**

Run: `php vendor/bin/phpunit`
Expected: Output ending in `OK, but there were issues!` or `No tests executed!` (no fatal errors) — confirms PHPUnit and PHP are wired up correctly.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock phpunit.xml .gitignore backend tests frontend static uploads
git commit -m "chore: scaffold project structure and PHPUnit"
```

---

### Task 1: Database schema, config, and PDO connection

**Files:**
- Create: `backend/schema.sql`
- Create: `backend/config.example.php`
- Create: `backend/db.php`
- Create: `README.md`

- [ ] **Step 1: Write the schema file**

`backend/schema.sql`:
```sql
CREATE DATABASE IF NOT EXISTS find_a_buddy CHARACTER SET utf8mb4;
USE find_a_buddy;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  preferred_locations VARCHAR(255),
  availability TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(20) NOT NULL,
  section VARCHAR(10),
  course_name VARCHAR(255)
);

CREATE TABLE user_courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  course_id INT NOT NULL,
  UNIQUE KEY unique_user_course (user_id, course_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE buddy_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  course_id INT,
  status ENUM('pending','accepted','declined') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id)
);

CREATE TABLE mascot_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  sender ENUM('user','mascot') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

- [ ] **Step 2: Import the schema**

Run: `mysql -u root find_a_buddy < backend/schema.sql`

(If `mysql` isn't on PATH, open Laragon → **Database** button, which opens HeidiSQL, and run the contents of `backend/schema.sql` there instead.)

Expected: no errors; a `find_a_buddy` database exists with the 4 tables.

- [ ] **Step 3: Create the config template**

`backend/config.example.php`:
```php
<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'find_a_buddy',
        'user' => 'root',
        'pass' => '',
    ],
    'anthropic_api_key' => 'YOUR_ANTHROPIC_API_KEY_HERE',
    'anthropic_model' => 'claude-haiku-4-5-20251001',
];
```

- [ ] **Step 4: Create the real (gitignored) config by copying the template**

Run: `cp backend/config.example.php backend/config.php`

Leave the placeholder API key for now — Task 12 (mascot chat) will remind you to fill it in with a real Anthropic API key before that feature works. Everything else in the plan works without it.

- [ ] **Step 5: Write `db.php`**

`backend/db.php`:
```php
<?php

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/config.php';
        $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
```

- [ ] **Step 6: Verify the connection**

Run: `php -r "require 'backend/db.php'; var_dump(get_db() instanceof PDO);"`
Expected: `bool(true)`

- [ ] **Step 7: Write the README**

`README.md`:
```markdown
# Find a Buddy

SSU study-buddy matching app. See `docs/superpowers/specs/2026-09-10-find-a-buddy-design.md` for the full design.

## Local setup (Laragon)

1. Install [Laragon](https://laragon.org/download/) to the default `C:\laragon` path, start Apache + MySQL.
2. Place this project at `C:\laragon\www\find-a-buddy`.
3. Import the schema: open Laragon → **Database** (HeidiSQL) and run `backend/schema.sql`, or `mysql -u root find_a_buddy < backend/schema.sql`.
4. Copy `backend/config.example.php` to `backend/config.php` and fill in your local DB credentials (defaults work with a stock Laragon MySQL install: user `root`, empty password).
5. Get an Anthropic API key at https://console.anthropic.com/ and paste it into `backend/config.php` as `anthropic_api_key` to enable the AI Study Mascot chat.
6. Install PHP dev dependencies: `composer install`.
7. Visit `http://find-a-buddy.test/frontend/` in your browser.

## Running tests

```bash
php vendor/bin/phpunit
```
```

- [ ] **Step 8: Commit**

```bash
git add backend/schema.sql backend/config.example.php backend/db.php README.md
git commit -m "feat: add DB schema, config template, and PDO connection"
```

---

### Task 2: Validation library (TDD)

**Files:**
- Create: `backend/lib/validation.php`
- Test: `tests/ValidationTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/ValidationTest.php`:
```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/validation.php';

class ValidationTest extends TestCase
{
    public function test_accepts_valid_ssu_email(): void
    {
        $this->assertTrue(is_valid_ssu_email('jdoe@savannahstate.edu'));
    }

    public function test_rejects_non_ssu_email(): void
    {
        $this->assertFalse(is_valid_ssu_email('jdoe@gmail.com'));
    }

    public function test_rejects_malformed_email(): void
    {
        $this->assertFalse(is_valid_ssu_email('not-an-email'));
    }

    public function test_validate_registration_input_flags_all_missing_fields(): void
    {
        $errors = validate_registration_input([]);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('first_name', $errors);
        $this->assertArrayHasKey('last_name', $errors);
    }

    public function test_validate_registration_input_rejects_short_password(): void
    {
        $errors = validate_registration_input([
            'email' => 'jdoe@savannahstate.edu',
            'password' => 'short',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_validate_registration_input_passes_for_good_data(): void
    {
        $errors = validate_registration_input([
            'email' => 'jdoe@savannahstate.edu',
            'password' => 'supersecret1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->assertSame([], $errors);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/ValidationTest.php`
Expected: FAIL — `Call to undefined function is_valid_ssu_email()`

- [ ] **Step 3: Implement the validation library**

`backend/lib/validation.php`:
```php
<?php

function is_valid_ssu_email(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return (bool) preg_match('/@savannahstate\.edu$/i', $email);
}

function validate_registration_input(array $data): array
{
    $errors = [];

    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');

    if ($email === '' || !is_valid_ssu_email($email)) {
        $errors['email'] = 'Email must be a valid @savannahstate.edu address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    }

    return $errors;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/ValidationTest.php`
Expected: `OK (6 tests, 6 assertions)`

- [ ] **Step 5: Commit**

```bash
git add backend/lib/validation.php tests/ValidationTest.php
git commit -m "feat: add registration validation library with tests"
```

---

### Task 3: Availability library (TDD)

**Files:**
- Create: `backend/lib/availability.php`
- Test: `tests/AvailabilityTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/AvailabilityTest.php`:
```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/availability.php';

class AvailabilityTest extends TestCase
{
    public function test_encode_availability_keeps_only_valid_slots(): void
    {
        $json = encode_availability(['Mon-Morning', 'Tue-Evening', 'Bogus-Slot', 'Fri-Afternoon']);
        $this->assertSame(['Mon-Morning', 'Tue-Evening', 'Fri-Afternoon'], json_decode($json, true));
    }

    public function test_encode_availability_dedupes(): void
    {
        $json = encode_availability(['Mon-Morning', 'Mon-Morning']);
        $this->assertSame(['Mon-Morning'], json_decode($json, true));
    }

    public function test_decode_availability_handles_empty_and_null(): void
    {
        $this->assertSame([], decode_availability(null));
        $this->assertSame([], decode_availability(''));
    }

    public function test_decode_availability_parses_json(): void
    {
        $this->assertSame(['Mon-Morning'], decode_availability('["Mon-Morning"]'));
    }

    public function test_overlapping_availability_returns_shared_slots_only(): void
    {
        $a = encode_availability(['Mon-Morning', 'Tue-Evening']);
        $b = encode_availability(['Tue-Evening', 'Wed-Afternoon']);
        $this->assertSame(['Tue-Evening'], overlapping_availability($a, $b));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/AvailabilityTest.php`
Expected: FAIL — `Call to undefined function encode_availability()`

- [ ] **Step 3: Implement the availability library**

`backend/lib/availability.php`:
```php
<?php

const AVAILABILITY_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
const AVAILABILITY_BLOCKS = ['Morning', 'Afternoon', 'Evening'];

function encode_availability(array $slots): string
{
    $valid = [];
    foreach ($slots as $slot) {
        [$day, $block] = array_pad(explode('-', (string) $slot, 2), 2, null);
        if (in_array($day, AVAILABILITY_DAYS, true) && in_array($block, AVAILABILITY_BLOCKS, true)) {
            $valid[] = "$day-$block";
        }
    }
    return json_encode(array_values(array_unique($valid)));
}

function decode_availability(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function overlapping_availability(string $jsonA, string $jsonB): array
{
    $a = decode_availability($jsonA);
    $b = decode_availability($jsonB);
    return array_values(array_intersect($a, $b));
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/AvailabilityTest.php`
Expected: `OK (5 tests, 6 assertions)`

- [ ] **Step 5: Commit**

```bash
git add backend/lib/availability.php tests/AvailabilityTest.php
git commit -m "feat: add availability encode/decode/overlap library with tests"
```

---

### Task 4: Session bootstrap and login guard

**Files:**
- Create: `backend/session_bootstrap.php`
- Create: `backend/check_session.php`

- [ ] **Step 1: Write the session bootstrap**

`backend/session_bootstrap.php`:
```php
<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

- [ ] **Step 2: Write the login guard**

`backend/check_session.php`:
```php
<?php

require_once __DIR__ . '/session_bootstrap.php';

function require_login(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated.']);
        exit;
    }
    return (int) $_SESSION['user_id'];
}
```

- [ ] **Step 3: Verify with PHP's syntax checker**

Run: `php -l backend/session_bootstrap.php && php -l backend/check_session.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Commit**

```bash
git add backend/session_bootstrap.php backend/check_session.php
git commit -m "feat: add session bootstrap and login guard"
```

---

### Task 5: Register endpoint + register page

**Files:**
- Create: `backend/register.php`
- Create: `frontend/register.html`

- [ ] **Step 1: Write the register endpoint**

`backend/register.php`:
```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/validation.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$errors = validate_registration_input($input);

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['errors' => $errors]);
    exit;
}

$pdo = get_db();
$email = trim($input['email']);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['errors' => ['email' => 'An account with this email already exists.']]);
    exit;
}

$hash = password_hash($input['password'], PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)');
$stmt->execute([$email, $hash, trim($input['first_name']), trim($input['last_name'])]);

echo json_encode(['success' => true]);
```

- [ ] **Step 2: Write the register page**

`frontend/register.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register — Find a Buddy</title>
<link rel="stylesheet" href="../static/login.css">
</head>
<body>
<main class="auth-card">
  <h1>Create your account</h1>
  <form id="register-form" novalidate>
    <label>SSU Email <input type="email" name="email" placeholder="you@savannahstate.edu" required></label>
    <label>Password <input type="password" name="password" minlength="8" required></label>
    <label>First name <input type="text" name="first_name" required></label>
    <label>Last name <input type="text" name="last_name" required></label>
    <p id="form-error" class="form-error" hidden></p>
    <button type="submit">Register</button>
  </form>
  <p>Already have an account? <a href="login.html">Log in</a></p>
</main>
<script src="api.js"></script>
<script>
  const form = document.getElementById('register-form');
  const errorEl = document.getElementById('form-error');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorEl.hidden = true;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      await api.register(data);
      window.location.href = 'login.html';
    } catch (err) {
      const messages = err.data && err.data.errors ? Object.values(err.data.errors) : [err.message];
      errorEl.textContent = messages.join(' ');
      errorEl.hidden = false;
    }
  });
</script>
</body>
</html>
```

- [ ] **Step 3: Create the shared `api.js` with just the pieces needed so far**

`frontend/api.js`:
```js
const API_BASE = '../backend';

async function apiRequest(path, options = {}) {
  const response = await fetch(`${API_BASE}/${path}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.error || 'Request failed');
    error.data = data;
    throw error;
  }
  return data;
}

const api = {
  register: (payload) => apiRequest('register.php', { method: 'POST', body: JSON.stringify(payload) }),
};
```

- [ ] **Step 4: Verify PHP syntax**

Run: `php -l backend/register.php`
Expected: `No syntax errors detected in backend/register.php`

- [ ] **Step 5: Manual browser verification**

With Laragon running and the project at `C:\laragon\www\find-a-buddy`, visit `http://find-a-buddy.test/frontend/register.html`, submit the form with an `@savannahstate.edu` email and an 8+ character password.
Expected: redirected to `login.html`. Confirm the row exists: `mysql -u root find_a_buddy -e "SELECT email, first_name FROM users;"` shows the new user with a bcrypt `password_hash` (starts with `$2y$`).

- [ ] **Step 6: Commit**

```bash
git add backend/register.php frontend/register.html frontend/api.js
git commit -m "feat: add registration endpoint and page"
```

---

### Task 6: Login, logout, and session pages

**Files:**
- Create: `backend/login.php`
- Create: `backend/logout.php`
- Create: `frontend/login.html`
- Create: `frontend/index.html`
- Modify: `frontend/api.js`

- [ ] **Step 1: Write the login endpoint**

`backend/login.php`:
```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';

session_start();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id, password_hash, first_name, last_name FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['first_name'] = $user['first_name'];

echo json_encode([
    'success' => true,
    'user' => ['id' => $user['id'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name']],
]);
```

- [ ] **Step 2: Write the logout endpoint**

`backend/logout.php`:
```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';

require_login();
$_SESSION = [];
session_destroy();

echo json_encode(['success' => true]);
```

- [ ] **Step 3: Add login/logout wrappers to `api.js`**

`frontend/api.js` (add to the `api` object):
```js
  login: (payload) => apiRequest('login.php', { method: 'POST', body: JSON.stringify(payload) }),
  logout: () => apiRequest('logout.php', { method: 'POST' }),
```

- [ ] **Step 4: Write the login page**

`frontend/login.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — Find a Buddy</title>
<link rel="stylesheet" href="../static/login.css">
</head>
<body>
<main class="auth-card">
  <h1>Log in</h1>
  <form id="login-form" novalidate>
    <label>SSU Email <input type="email" name="email" required></label>
    <label>Password <input type="password" name="password" required></label>
    <p id="form-error" class="form-error" hidden></p>
    <button type="submit">Log in</button>
  </form>
  <p>Need an account? <a href="register.html">Register</a></p>
</main>
<script src="api.js"></script>
<script>
  const form = document.getElementById('login-form');
  const errorEl = document.getElementById('form-error');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorEl.hidden = true;
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      await api.login(data);
      window.location.href = 'buddy.html';
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.hidden = false;
    }
  });
</script>
</body>
</html>
```

- [ ] **Step 5: Write the landing/redirect page**

`frontend/index.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Find a Buddy</title>
</head>
<body>
<script src="api.js"></script>
<script>
  api.getUserInfo()
    .then(() => { window.location.href = 'buddy.html'; })
    .catch(() => { window.location.href = 'login.html'; });
</script>
<noscript>Please enable JavaScript, then visit <a href="login.html">login.html</a>.</noscript>
</body>
</html>
```

Note: `index.html` calls `api.getUserInfo`, which is added in Task 8. Until then this page will error in the console — that's expected and resolved by Task 8.

- [ ] **Step 6: Verify PHP syntax**

Run: `php -l backend/login.php && php -l backend/logout.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 7: Manual browser verification**

Visit `http://find-a-buddy.test/frontend/login.html`, log in with the account created in Task 5.
Expected: redirected to `buddy.html` (404 is fine/expected — that page doesn't exist until Task 10). Confirm a `PHPSESSID` cookie was set (check DevTools → Application → Cookies) and that it's marked `HttpOnly`.

- [ ] **Step 8: Commit**

```bash
git add backend/login.php backend/logout.php frontend/login.html frontend/index.html frontend/api.js
git commit -m "feat: add login/logout endpoints and pages"
```

---

### Task 7: Matching library (TDD)

**Files:**
- Create: `backend/lib/matching.php`
- Test: `tests/MatchingTest.php`

- [ ] **Step 1: Write the failing test**

`tests/MatchingTest.php`:
```php
<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/matching.php';

class MatchingTest extends TestCase
{
    public function test_shared_course_ids_returns_intersection(): void
    {
        $this->assertSame([2, 3], array_values(shared_course_ids([1, 2, 3], [2, 3, 4])));
    }

    public function test_shared_course_ids_returns_empty_when_no_overlap(): void
    {
        $this->assertSame([], shared_course_ids([1, 2], [3, 4]));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php vendor/bin/phpunit tests/MatchingTest.php`
Expected: FAIL — `Call to undefined function shared_course_ids()`

- [ ] **Step 3: Implement**

`backend/lib/matching.php`:
```php
<?php

function shared_course_ids(array $courseIdsA, array $courseIdsB): array
{
    return array_values(array_intersect($courseIdsA, $courseIdsB));
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php vendor/bin/phpunit tests/MatchingTest.php`
Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 5: Commit**

```bash
git add backend/lib/matching.php tests/MatchingTest.php
git commit -m "feat: add course-matching library with tests"
```

---

### Task 8: User info endpoint + profile page

**Files:**
- Create: `backend/get_user_info.php`
- Create: `frontend/profile.html`
- Modify: `frontend/api.js`

- [ ] **Step 1: Write the endpoint**

`backend/get_user_info.php`:
```php
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
```

- [ ] **Step 2: Add the wrapper to `api.js`**

`frontend/api.js` (add to the `api` object):
```js
  getUserInfo: () => apiRequest('get_user_info.php'),
```

- [ ] **Step 3: Write the profile page**

`frontend/profile.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Profile — Find a Buddy</title>
<link rel="stylesheet" href="../static/main.css">
</head>
<body>
<nav class="app-nav">
  <a href="buddy.html">Find a Buddy</a>
  <a href="courses.html">My Courses</a>
  <a href="mascot.html">Study Mascot</a>
  <a href="profile.html" class="active">Profile</a>
  <button id="logout-btn">Log out</button>
</nav>
<main>
  <h1>My Profile</h1>
  <dl id="profile-fields"></dl>
</main>
<script src="api.js"></script>
<script>
  api.getUserInfo().then(({ user }) => {
    const dl = document.getElementById('profile-fields');
    dl.innerHTML = `
      <dt>Name</dt><dd>${user.first_name} ${user.last_name}</dd>
      <dt>Email</dt><dd>${user.email}</dd>
      <dt>Preferred locations</dt><dd>${user.preferred_locations || 'Not set'}</dd>
    `;
  }).catch(() => { window.location.href = 'login.html'; });

  document.getElementById('logout-btn').addEventListener('click', async () => {
    await api.logout();
    window.location.href = 'login.html';
  });
</script>
</body>
</html>
```

- [ ] **Step 4: Verify PHP syntax**

Run: `php -l backend/get_user_info.php`
Expected: `No syntax errors detected in backend/get_user_info.php`

- [ ] **Step 5: Manual browser verification**

While logged in (Task 6), visit `http://find-a-buddy.test/frontend/profile.html`.
Expected: your name and email render; `index.html` (Task 6) now correctly redirects logged-in users to `buddy.html` instead of erroring.

- [ ] **Step 6: Commit**

```bash
git add backend/get_user_info.php frontend/profile.html frontend/api.js
git commit -m "feat: add get_user_info endpoint and profile page"
```

---

### Task 9: Courses — endpoints and page

**Files:**
- Create: `backend/get_courses.php`
- Create: `backend/save_courses.php`
- Create: `frontend/courses.html`
- Modify: `frontend/api.js`

- [ ] **Step 1: Write `get_courses.php`**

```php
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
```

- [ ] **Step 2: Write `save_courses.php`**

```php
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

        $stmt = $pdo->prepare('SELECT id FROM courses WHERE course_code = ? AND section <=> ?');
        $stmt->execute([$code, $section !== '' ? $section : null]);
        $courseId = $stmt->fetchColumn();

        if (!$courseId) {
            $stmt = $pdo->prepare('INSERT INTO courses (course_code, section, course_name) VALUES (?, ?, ?)');
            $stmt->execute([$code, $section !== '' ? $section : null, $name]);
            $courseId = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO user_courses (user_id, course_id) VALUES (?, ?)');
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
```

- [ ] **Step 3: Add wrappers to `api.js`**

`frontend/api.js` (add to the `api` object):
```js
  getCourses: (q) => apiRequest(`get_courses.php${q ? `?q=${encodeURIComponent(q)}` : ''}`),
  saveCourses: (payload) => apiRequest('save_courses.php', { method: 'POST', body: JSON.stringify(payload) }),
```

- [ ] **Step 4: Write the courses page**

`frontend/courses.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Courses — Find a Buddy</title>
<link rel="stylesheet" href="../static/main.css">
</head>
<body>
<nav class="app-nav">
  <a href="buddy.html">Find a Buddy</a>
  <a href="courses.html" class="active">My Courses</a>
  <a href="mascot.html">Study Mascot</a>
  <a href="profile.html">Profile</a>
</nav>
<main>
  <h1>My Courses</h1>

  <section>
    <h2>Add a course</h2>
    <input type="text" id="course-search" placeholder="Search course code or name (e.g. CSCI 3350)" autocomplete="off">
    <ul id="course-suggestions" class="suggestions" hidden></ul>
    <form id="new-course-form" hidden>
      <input type="hidden" name="course_code" id="new-course-code">
      <input type="text" name="section" placeholder="Section (optional)">
      <input type="text" name="course_name" placeholder="Course name">
      <button type="submit">Add course</button>
    </form>
  </section>

  <section>
    <h2>Current courses</h2>
    <ul id="course-list"></ul>
  </section>

  <section>
    <h2>Preferred study locations</h2>
    <label><input type="checkbox" name="location" value="Library"> Library</label>
    <label><input type="checkbox" name="location" value="Student Union"> Student Union</label>
    <label><input type="checkbox" name="location" value="Dorm Lounge"> Dorm Lounge</label>
    <label><input type="checkbox" name="location" value="Online"> Online</label>
  </section>

  <section>
    <h2>Availability</h2>
    <table id="availability-grid">
      <thead><tr><th></th><th>Morning</th><th>Afternoon</th><th>Evening</th></tr></thead>
      <tbody>
        <tr><th>Mon</th><td><input type="checkbox" data-slot="Mon-Morning"></td><td><input type="checkbox" data-slot="Mon-Afternoon"></td><td><input type="checkbox" data-slot="Mon-Evening"></td></tr>
        <tr><th>Tue</th><td><input type="checkbox" data-slot="Tue-Morning"></td><td><input type="checkbox" data-slot="Tue-Afternoon"></td><td><input type="checkbox" data-slot="Tue-Evening"></td></tr>
        <tr><th>Wed</th><td><input type="checkbox" data-slot="Wed-Morning"></td><td><input type="checkbox" data-slot="Wed-Afternoon"></td><td><input type="checkbox" data-slot="Wed-Evening"></td></tr>
        <tr><th>Thu</th><td><input type="checkbox" data-slot="Thu-Morning"></td><td><input type="checkbox" data-slot="Thu-Afternoon"></td><td><input type="checkbox" data-slot="Thu-Evening"></td></tr>
        <tr><th>Fri</th><td><input type="checkbox" data-slot="Fri-Morning"></td><td><input type="checkbox" data-slot="Fri-Afternoon"></td><td><input type="checkbox" data-slot="Fri-Evening"></td></tr>
      </tbody>
    </table>
  </section>

  <button id="save-preferences-btn">Save locations & availability</button>
  <p id="save-status" hidden></p>
</main>
<script src="api.js"></script>
<script>
  const searchInput = document.getElementById('course-search');
  const suggestionsEl = document.getElementById('course-suggestions');
  const courseListEl = document.getElementById('course-list');

  async function loadCourses() {
    const { courses } = await api.getCourses();
    courseListEl.innerHTML = courses.map(c => `
      <li>${c.course_code}${c.section ? ' — ' + c.section : ''} ${c.course_name || ''}
        <button data-remove="${c.id}">Remove</button>
      </li>
    `).join('') || '<li>No courses yet.</li>';

    courseListEl.querySelectorAll('[data-remove]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await api.saveCourses({ remove_course_id: Number(btn.dataset.remove) });
        loadCourses();
      });
    });
  }

  searchInput.addEventListener('input', async () => {
    const q = searchInput.value.trim();
    if (q.length < 2) {
      suggestionsEl.hidden = true;
      return;
    }
    const { courses } = await api.getCourses(q);
    suggestionsEl.innerHTML = courses.map(c =>
      `<li data-code="${c.course_code}" data-section="${c.section || ''}" data-name="${c.course_name || ''}">${c.course_code}${c.section ? ' — ' + c.section : ''} ${c.course_name || ''}</li>`
    ).join('') + `<li data-new="${q}">+ Add "${q}" as a new course</li>`;
    suggestionsEl.hidden = false;
  });

  suggestionsEl.addEventListener('click', async (event) => {
    const li = event.target.closest('li');
    if (!li) return;
    suggestionsEl.hidden = true;
    searchInput.value = '';

    if (li.dataset.new !== undefined) {
      const name = prompt('Course name?') || '';
      await api.saveCourses({ courses: [{ course_code: li.dataset.new, course_name: name }] });
    } else {
      await api.saveCourses({ courses: [{ course_code: li.dataset.code, section: li.dataset.section, course_name: li.dataset.name }] });
    }
    loadCourses();
  });

  document.getElementById('save-preferences-btn').addEventListener('click', async () => {
    const locations = Array.from(document.querySelectorAll('input[name="location"]:checked')).map(el => el.value).join(', ');
    const availability = Array.from(document.querySelectorAll('#availability-grid input:checked')).map(el => el.dataset.slot);
    await api.saveCourses({ preferred_locations: locations, availability });
    const status = document.getElementById('save-status');
    status.textContent = 'Saved!';
    status.hidden = false;
  });

  api.getUserInfo().then(loadCourses).catch(() => { window.location.href = 'login.html'; });
</script>
</body>
</html>
```

- [ ] **Step 5: Verify PHP syntax**

Run: `php -l backend/get_courses.php && php -l backend/save_courses.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Manual browser verification**

Visit `courses.html`, search "CSCI", add a new course "CSCI 3350" with a name, check a couple of availability boxes and a location, save. Reload the page.
Expected: the course persists in the list, and `SELECT availability, preferred_locations FROM users WHERE email='...'` shows the saved JSON/text.

- [ ] **Step 7: Commit**

```bash
git add backend/get_courses.php backend/save_courses.php frontend/courses.html frontend/api.js
git commit -m "feat: add course management endpoints and page"
```

---

### Task 10: Buddy search endpoint + Find a Buddy page (search tab)

**Files:**
- Create: `backend/search_buddies.php`
- Create: `frontend/buddy.html`
- Create: `frontend/buddyView.js`
- Modify: `frontend/api.js`

- [ ] **Step 1: Write the search endpoint**

`backend/search_buddies.php`:
```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/availability.php';

$userId = require_login();
$pdo = get_db();

$stmt = $pdo->prepare('SELECT availability FROM users WHERE id = ?');
$stmt->execute([$userId]);
$myAvailability = $stmt->fetchColumn() ?: '[]';

$stmt = $pdo->prepare('
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.availability
    FROM user_courses uc
    JOIN user_courses my_uc ON my_uc.course_id = uc.course_id AND my_uc.user_id = ?
    JOIN users u ON u.id = uc.user_id
    WHERE uc.user_id != ?
');
$stmt->execute([$userId, $userId]);
$candidates = $stmt->fetchAll();

$sharedStmt = $pdo->prepare('
    SELECT c.id, c.course_code, c.section, c.course_name
    FROM user_courses uc1
    JOIN user_courses uc2 ON uc1.course_id = uc2.course_id
    JOIN courses c ON c.id = uc1.course_id
    WHERE uc1.user_id = ? AND uc2.user_id = ?
');

$results = [];
foreach ($candidates as $candidate) {
    $sharedStmt->execute([$userId, $candidate['id']]);
    $results[] = [
        'user_id' => $candidate['id'],
        'first_name' => $candidate['first_name'],
        'last_initial' => strtoupper(substr($candidate['last_name'], 0, 1)),
        'shared_courses' => $sharedStmt->fetchAll(),
        'overlapping_availability' => overlapping_availability($myAvailability, $candidate['availability'] ?: '[]'),
    ];
}

echo json_encode(['results' => $results]);
```

- [ ] **Step 2: Add wrapper to `api.js`**

`frontend/api.js` (add to the `api` object):
```js
  searchBuddies: () => apiRequest('search_buddies.php'),
  sendBuddyRequest: (payload) => apiRequest('send_buddy_request.php', { method: 'POST', body: JSON.stringify(payload) }),
```

(`send_buddy_request.php` itself is built in Task 11; the wrapper is added now since the search tab's "Send Request" button needs it.)

- [ ] **Step 3: Write `buddyView.js`**

`frontend/buddyView.js`:
```js
async function renderSearchTab(container) {
  const { results } = await api.searchBuddies();
  container.innerHTML = results.length ? results.map(r => `
    <li>
      <strong>${r.first_name} ${r.last_initial}.</strong>
      — shares: ${r.shared_courses.map(c => c.course_code).join(', ')}
      ${r.overlapping_availability.length ? `<br>Available: ${r.overlapping_availability.join(', ')}` : ''}
      <button data-request="${r.user_id}" data-course="${r.shared_courses[0] ? r.shared_courses[0].id : ''}">Send Buddy Request</button>
    </li>
  `).join('') : '<li>No shared-course matches yet.</li>';

  container.querySelectorAll('[data-request]').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api.sendBuddyRequest({ receiver_id: Number(btn.dataset.request), course_id: btn.dataset.course ? Number(btn.dataset.course) : null });
        btn.textContent = 'Request sent';
      } catch (err) {
        btn.textContent = err.message;
        btn.disabled = false;
      }
    });
  });
}
```

- [ ] **Step 4: Write the Find a Buddy page**

`frontend/buddy.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Find a Buddy</title>
<link rel="stylesheet" href="../static/main.css">
</head>
<body>
<nav class="app-nav">
  <a href="buddy.html" class="active">Find a Buddy</a>
  <a href="courses.html">My Courses</a>
  <a href="mascot.html">Study Mascot</a>
  <a href="profile.html">Profile</a>
</nav>
<main>
  <h1>Find a Buddy</h1>
  <div class="tabs">
    <button data-tab="search" class="tab-active">Search</button>
    <button data-tab="incoming">Incoming Requests</button>
    <button data-tab="sent">Sent Requests</button>
    <button data-tab="buddies">My Buddies</button>
  </div>
  <ul id="tab-content"></ul>
</main>
<script src="api.js"></script>
<script src="buddyView.js"></script>
<script>
  const tabButtons = document.querySelectorAll('[data-tab]');
  const content = document.getElementById('tab-content');

  async function showTab(tab) {
    tabButtons.forEach(b => b.classList.toggle('tab-active', b.dataset.tab === tab));
    if (tab === 'search') {
      await renderSearchTab(content);
    } else {
      content.innerHTML = '<li>Loading…</li>';
    }
  }

  tabButtons.forEach(btn => btn.addEventListener('click', () => showTab(btn.dataset.tab)));

  api.getUserInfo().then(() => showTab('search')).catch(() => { window.location.href = 'login.html'; });
</script>
</body>
</html>
```

(The `incoming`/`sent`/`buddies` tabs are wired up fully in Task 11 alongside their endpoints — right now clicking them shows "Loading…" and nothing else.)

- [ ] **Step 5: Verify PHP syntax**

Run: `php -l backend/search_buddies.php`
Expected: `No syntax errors detected in backend/search_buddies.php`

- [ ] **Step 6: Manual browser verification**

Register a second test account sharing a course with the first (via `courses.html`), then visit `buddy.html` on the first account's session.
Expected: the second student appears in the Search tab list with the shared course code shown.

- [ ] **Step 7: Commit**

```bash
git add backend/search_buddies.php frontend/buddy.html frontend/buddyView.js frontend/api.js
git commit -m "feat: add buddy search endpoint and search tab"
```

---

### Task 11: Buddy requests — send/respond/list endpoints + remaining tabs

**Files:**
- Create: `backend/send_buddy_request.php`
- Create: `backend/respond_buddy_request.php`
- Create: `backend/get_buddy_requests.php`
- Modify: `frontend/buddyView.js`
- Modify: `frontend/buddy.html`
- Modify: `frontend/api.js`

- [ ] **Step 1: Write `send_buddy_request.php`**

```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$receiverId = (int) ($input['receiver_id'] ?? 0);
$courseId = isset($input['course_id']) && $input['course_id'] !== null ? (int) $input['course_id'] : null;

if ($receiverId <= 0 || $receiverId === $userId) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid recipient.']);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id FROM buddy_requests WHERE sender_id = ? AND receiver_id = ? AND status = "pending"');
$stmt->execute([$userId, $receiverId]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Request already sent.']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO buddy_requests (sender_id, receiver_id, course_id) VALUES (?, ?, ?)');
$stmt->execute([$userId, $receiverId, $courseId]);

echo json_encode(['success' => true]);
```

- [ ] **Step 2: Write `respond_buddy_request.php`**

```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$requestId = (int) ($input['request_id'] ?? 0);
$action = $input['action'] ?? '';

if (!in_array($action, ['accepted', 'declined'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Action must be "accepted" or "declined".']);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id FROM buddy_requests WHERE id = ? AND receiver_id = ? AND status = "pending"');
$stmt->execute([$requestId, $userId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Request not found.']);
    exit;
}

$stmt = $pdo->prepare('UPDATE buddy_requests SET status = ? WHERE id = ?');
$stmt->execute([$action, $requestId]);

echo json_encode(['success' => true]);
```

- [ ] **Step 3: Write `get_buddy_requests.php`**

```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

$userId = require_login();
$pdo = get_db();

$stmt = $pdo->prepare('
    SELECT br.id, br.course_id, br.created_at, u.id AS other_id, u.first_name, u.last_name
    FROM buddy_requests br
    JOIN users u ON u.id = br.sender_id
    WHERE br.receiver_id = ? AND br.status = "pending"
');
$stmt->execute([$userId]);
$incoming = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT br.id, br.course_id, br.created_at, u.id AS other_id, u.first_name, u.last_name
    FROM buddy_requests br
    JOIN users u ON u.id = br.receiver_id
    WHERE br.sender_id = ? AND br.status = "pending"
');
$stmt->execute([$userId]);
$sent = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT br.id, u.id AS other_id, u.first_name, u.last_name, u.email
    FROM buddy_requests br
    JOIN users u ON u.id = (CASE WHEN br.sender_id = ? THEN br.receiver_id ELSE br.sender_id END)
    WHERE (br.sender_id = ? OR br.receiver_id = ?) AND br.status = "accepted"
');
$stmt->execute([$userId, $userId, $userId]);
$buddies = $stmt->fetchAll();

echo json_encode(['incoming' => $incoming, 'sent' => $sent, 'buddies' => $buddies]);
```

- [ ] **Step 4: Add wrappers to `api.js`**

`frontend/api.js` (add to the `api` object):
```js
  respondBuddyRequest: (payload) => apiRequest('respond_buddy_request.php', { method: 'POST', body: JSON.stringify(payload) }),
  getBuddyRequests: () => apiRequest('get_buddy_requests.php'),
```

- [ ] **Step 5: Extend `buddyView.js` with the remaining tabs**

`frontend/buddyView.js` (append):
```js
async function renderIncomingTab(container) {
  const { incoming } = await api.getBuddyRequests();
  container.innerHTML = incoming.length ? incoming.map(r => `
    <li>${r.first_name} ${r.last_name} wants to study together.
      <button data-accept="${r.id}">Accept</button>
      <button data-decline="${r.id}">Decline</button>
    </li>
  `).join('') : '<li>No incoming requests.</li>';

  container.querySelectorAll('[data-accept]').forEach(btn =>
    btn.addEventListener('click', async () => {
      await api.respondBuddyRequest({ request_id: Number(btn.dataset.accept), action: 'accepted' });
      renderIncomingTab(container);
    })
  );
  container.querySelectorAll('[data-decline]').forEach(btn =>
    btn.addEventListener('click', async () => {
      await api.respondBuddyRequest({ request_id: Number(btn.dataset.decline), action: 'declined' });
      renderIncomingTab(container);
    })
  );
}

async function renderSentTab(container) {
  const { sent } = await api.getBuddyRequests();
  container.innerHTML = sent.length
    ? sent.map(r => `<li>Request to ${r.first_name} ${r.last_name} — pending</li>`).join('')
    : '<li>No sent requests.</li>';
}

async function renderBuddiesTab(container) {
  const { buddies } = await api.getBuddyRequests();
  container.innerHTML = buddies.length
    ? buddies.map(b => `<li>${b.first_name} ${b.last_name} — <a href="mailto:${b.email}">${b.email}</a></li>`).join('')
    : '<li>No buddies yet — accept or send a request!</li>';
}
```

- [ ] **Step 6: Wire the new tabs into `buddy.html`**

In `frontend/buddy.html`, replace the `showTab` function body:
```js
  async function showTab(tab) {
    tabButtons.forEach(b => b.classList.toggle('tab-active', b.dataset.tab === tab));
    if (tab === 'search') await renderSearchTab(content);
    if (tab === 'incoming') await renderIncomingTab(content);
    if (tab === 'sent') await renderSentTab(content);
    if (tab === 'buddies') await renderBuddiesTab(content);
  }
```

- [ ] **Step 7: Verify PHP syntax**

Run: `php -l backend/send_buddy_request.php && php -l backend/respond_buddy_request.php && php -l backend/get_buddy_requests.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 8: Manual browser verification**

Using the two test accounts from Task 10: send a request from account A to account B (Search tab), log in as B and accept it (Incoming tab), then check My Buddies on both accounts.
Expected: A sees B (with email) in My Buddies, and vice versa; the email is NOT visible anywhere before acceptance.

- [ ] **Step 9: Commit**

```bash
git add backend/send_buddy_request.php backend/respond_buddy_request.php backend/get_buddy_requests.php frontend/buddyView.js frontend/buddy.html frontend/api.js
git commit -m "feat: add buddy request send/respond/list endpoints and remaining tabs"
```

---

### Task 12: AI Study Mascot chat

**Files:**
- Create: `backend/mascot_chat.php`
- Create: `frontend/mascot.html`
- Create: `frontend/mascotView.js`
- Modify: `frontend/api.js`

- [ ] **Step 1: Write the mascot chat endpoint**

`backend/mascot_chat.php`:
```php
<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

function call_claude_mascot(array $config, array $messages): string
{
    $fallback = "I'm having trouble connecting right now, but you've got this — take a short break, then tackle one small task at a time!";

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $config['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $config['anthropic_model'],
            'max_tokens' => 300,
            'system' => 'You are Rowdy, the friendly Savannah State University study mascot. '
                . 'You give short, encouraging, upbeat study tips and motivation to SSU students. '
                . 'Stay focused on studying, motivation, and campus life — do not answer unrelated general knowledge questions.',
            'messages' => $messages,
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return $fallback;
    }

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? $fallback;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim($input['message'] ?? '');

if ($message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit;
}

$pdo = get_db();

$stmt = $pdo->prepare('INSERT INTO mascot_messages (user_id, sender, message) VALUES (?, "user", ?)');
$stmt->execute([$userId, $message]);

$stmt = $pdo->prepare('SELECT sender, message FROM mascot_messages WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$stmt->execute([$userId]);
$recent = array_reverse($stmt->fetchAll());

$config = require __DIR__ . '/config.php';
$apiMessages = array_map(fn($m) => [
    'role' => $m['sender'] === 'user' ? 'user' : 'assistant',
    'content' => $m['message'],
], $recent);

$reply = call_claude_mascot($config, $apiMessages);

$stmt = $pdo->prepare('INSERT INTO mascot_messages (user_id, sender, message) VALUES (?, "mascot", ?)');
$stmt->execute([$userId, $reply]);

echo json_encode(['reply' => $reply]);
```

- [ ] **Step 2: Add wrapper + history wrapper to `api.js`**

`frontend/api.js` (add to the `api` object):
```js
  mascotChat: (message) => apiRequest('mascot_chat.php', { method: 'POST', body: JSON.stringify({ message }) }),
```

- [ ] **Step 3: Write `mascotView.js`**

`frontend/mascotView.js`:
```js
function appendMessage(container, sender, text) {
  const li = document.createElement('li');
  li.className = sender === 'user' ? 'msg-user' : 'msg-mascot';
  li.textContent = text;
  container.appendChild(li);
  container.scrollTop = container.scrollHeight;
}

function initMascotChat(listEl, formEl, inputEl) {
  formEl.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = inputEl.value.trim();
    if (!text) return;
    appendMessage(listEl, 'user', text);
    inputEl.value = '';
    inputEl.disabled = true;
    try {
      const { reply } = await api.mascotChat(text);
      appendMessage(listEl, 'mascot', reply);
    } catch (err) {
      appendMessage(listEl, 'mascot', "I'm having trouble connecting right now, but you've got this!");
    } finally {
      inputEl.disabled = false;
      inputEl.focus();
    }
  });
}
```

- [ ] **Step 4: Write the mascot page**

`frontend/mascot.html`:
```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Study Mascot — Find a Buddy</title>
<link rel="stylesheet" href="../static/main.css">
</head>
<body>
<nav class="app-nav">
  <a href="buddy.html">Find a Buddy</a>
  <a href="courses.html">My Courses</a>
  <a href="mascot.html" class="active">Study Mascot</a>
  <a href="profile.html">Profile</a>
</nav>
<main>
  <h1>Chat with Rowdy 🐾</h1>
  <ul id="chat-log" class="chat-log"></ul>
  <form id="chat-form">
    <input type="text" id="chat-input" placeholder="Ask for a study tip or some motivation…" autocomplete="off" required>
    <button type="submit">Send</button>
  </form>
</main>
<script src="api.js"></script>
<script src="mascotView.js"></script>
<script>
  const listEl = document.getElementById('chat-log');
  const formEl = document.getElementById('chat-form');
  const inputEl = document.getElementById('chat-input');

  initMascotChat(listEl, formEl, inputEl);
  api.getUserInfo().catch(() => { window.location.href = 'login.html'; });
</script>
</body>
</html>
```

- [ ] **Step 5: Verify PHP syntax**

Run: `php -l backend/mascot_chat.php`
Expected: `No syntax errors detected in backend/mascot_chat.php`

- [ ] **Step 6: Fill in a real Anthropic API key**

Open `backend/config.php` and replace `YOUR_ANTHROPIC_API_KEY_HERE` with a real key from https://console.anthropic.com/. This file is gitignored, so the key never gets committed.

- [ ] **Step 7: Manual browser verification**

Visit `mascot.html`, send "I have a big exam tomorrow, any tips?".
Expected: an encouraging, study-focused reply appears. Reload the page — note history isn't re-rendered on load by this page (see the follow-up note below), but confirm persistence directly: `SELECT sender, message FROM mascot_messages ORDER BY id DESC LIMIT 4;` shows both your message and the mascot's reply. Temporarily set `anthropic_api_key` to an invalid string and resend a message — expected: the canned fallback message appears instead of an error.

- [ ] **Step 8: Commit**

```bash
git add backend/mascot_chat.php frontend/mascot.html frontend/mascotView.js frontend/api.js
git commit -m "feat: add AI study mascot chat endpoint and page"
```

---

### Task 13: Styling pass (mobile-first, SSU colors)

**Files:**
- Create: `static/main.css`
- Create: `static/login.css`

- [ ] **Step 1: Write shared design tokens + app layout in `main.css`**

`static/main.css`:
```css
:root {
  --ssu-navy: #041e42;
  --ssu-gold: #f2a900;
  --bg: #f7f7f5;
  --text: #1a1a1a;
  --card-bg: #ffffff;
  --border: #dcdcdc;
  --danger: #b3261e;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  background: var(--bg);
  color: var(--text);
}

.app-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  background: var(--ssu-navy);
  padding: 0.75rem 1rem;
}

.app-nav a, .app-nav button {
  color: white;
  text-decoration: none;
  padding: 0.5rem 0.75rem;
  border-radius: 4px;
  background: transparent;
  border: none;
  font-size: 1rem;
  cursor: pointer;
}

.app-nav a.active, .app-nav a:hover, .app-nav button:hover {
  background: var(--ssu-gold);
  color: var(--ssu-navy);
  font-weight: 600;
}

main {
  max-width: 720px;
  margin: 0 auto;
  padding: 1rem;
}

h1 {
  color: var(--ssu-navy);
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.tabs button {
  padding: 0.5rem 1rem;
  border: 1px solid var(--ssu-navy);
  background: white;
  color: var(--ssu-navy);
  border-radius: 4px;
  cursor: pointer;
}

.tabs button.tab-active {
  background: var(--ssu-navy);
  color: white;
}

#tab-content, #course-list, .suggestions {
  list-style: none;
  padding: 0;
}

#tab-content li, #course-list li {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 0.75rem;
  margin-bottom: 0.5rem;
}

button[data-request], button[data-accept] {
  background: var(--ssu-gold);
  border: none;
  padding: 0.4rem 0.8rem;
  border-radius: 4px;
  cursor: pointer;
}

button[data-decline] {
  background: transparent;
  border: 1px solid var(--danger);
  color: var(--danger);
  padding: 0.4rem 0.8rem;
  border-radius: 4px;
  cursor: pointer;
}

table#availability-grid {
  width: 100%;
  border-collapse: collapse;
}

table#availability-grid th, table#availability-grid td {
  border: 1px solid var(--border);
  padding: 0.5rem;
  text-align: center;
}

.chat-log {
  list-style: none;
  padding: 0.5rem;
  height: 50vh;
  overflow-y: auto;
  background: white;
  border: 1px solid var(--border);
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.chat-log .msg-user, .chat-log .msg-mascot {
  padding: 0.5rem 0.75rem;
  border-radius: 12px;
  max-width: 80%;
}

.chat-log .msg-user {
  align-self: flex-end;
  background: var(--ssu-navy);
  color: white;
}

.chat-log .msg-mascot {
  align-self: flex-start;
  background: var(--ssu-gold);
  color: var(--ssu-navy);
}

#chat-form {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

#chat-form input {
  flex: 1;
  padding: 0.6rem;
  border-radius: 4px;
  border: 1px solid var(--border);
}

#chat-form button {
  background: var(--ssu-navy);
  color: white;
  border: none;
  padding: 0.6rem 1.2rem;
  border-radius: 4px;
  cursor: pointer;
}

@media (max-width: 480px) {
  .app-nav { justify-content: space-between; }
  main { padding: 0.75rem; }
}
```

- [ ] **Step 2: Write `login.css`**

`static/login.css`:
```css
:root {
  --ssu-navy: #041e42;
  --ssu-gold: #f2a900;
  --bg: #f7f7f5;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--ssu-navy);
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
}

.auth-card {
  background: white;
  padding: 2rem;
  border-radius: 8px;
  width: 90%;
  max-width: 360px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
}

.auth-card h1 {
  color: var(--ssu-navy);
  margin-top: 0;
}

.auth-card form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.auth-card label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}

.auth-card input {
  padding: 0.6rem;
  border-radius: 4px;
  border: 1px solid #ccc;
  font-size: 1rem;
}

.auth-card button {
  background: var(--ssu-gold);
  color: var(--ssu-navy);
  border: none;
  padding: 0.7rem;
  border-radius: 4px;
  font-weight: 600;
  cursor: pointer;
}

.form-error {
  color: #b3261e;
  font-size: 0.85rem;
}

@media (max-width: 400px) {
  .auth-card { padding: 1.25rem; }
}
```

- [ ] **Step 3: Manual browser verification**

Open each page (`login.html`, `register.html`, `buddy.html`, `courses.html`, `mascot.html`, `profile.html`) at a 375px-wide viewport (Chrome DevTools device toolbar) and at desktop width.
Expected: no horizontal scrollbars, nav wraps cleanly, buttons are tappable (≥40px height), SSU navy/gold colors are visible in the nav, buttons, and chat bubbles.

- [ ] **Step 4: Commit**

```bash
git add static/main.css static/login.css
git commit -m "style: add mobile-first SSU-branded styling for all pages"
```

---

### Task 14: Full manual end-to-end test pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full automated test suite**

Run: `php vendor/bin/phpunit`
Expected: all tests across `ValidationTest`, `AvailabilityTest`, `MatchingTest` pass, e.g. `OK (13 tests, 15 assertions)`.

- [ ] **Step 2: Two-account end-to-end walkthrough**

1. Register Student A (`a@savannahstate.edu`) and Student B (`b@savannahstate.edu`).
2. Both add the same course (e.g. `CSCI 3350`) via `courses.html`, with different availability slots that share at least one overlapping block.
3. As A, go to `buddy.html` → Search tab, confirm B appears with the shared course and overlapping availability, click "Send Buddy Request".
4. As B, go to Incoming Requests, confirm A's request appears, click Accept.
5. As both A and B, check My Buddies — confirm the other's name AND email now appear.
6. As A, go to Search tab again — confirm B no longer shows a "Send Buddy Request" option in a state that would let you send a duplicate pending request (sending to an already-pending/accepted pair should be rejected — verify by re-triggering the button if still visible: expect a 409/error, not a silent duplicate row in `buddy_requests`).

- [ ] **Step 3: Contact-info leak check**

Run: `mysql -u root find_a_buddy -e "SELECT id FROM buddy_requests WHERE status='pending';"` and, while a request is pending (not yet accepted), inspect the Network tab response of `search_buddies.php` and `get_buddy_requests.php` (incoming/sent sections) in the browser.
Expected: no `email` field appears anywhere except inside the `buddies` array of `get_buddy_requests.php`.

- [ ] **Step 4: Mascot persistence check**

As Student A, send 2-3 messages in `mascot.html`, then log out and back in, and revisit `mascot.html`.
Expected: `SELECT COUNT(*) FROM mascot_messages WHERE user_id = (SELECT id FROM users WHERE email='a@savannahstate.edu');` reflects every user+mascot message pair sent. (The page itself doesn't replay history into the chat log on load per the current `mascot.html` — this step confirms the *data* persists as required by the spec; if you also want the UI to replay history on load, that's a small follow-up, not a spec requirement violation.)

- [ ] **Step 5: Security spot-check**

- Confirm `backend/config.php` is NOT tracked by git: `git status --porcelain backend/config.php` prints nothing (untracked/ignored).
- Confirm session cookie is `HttpOnly`: in DevTools → Application → Cookies, the `PHPSESSID` row has the HttpOnly flag checked.
- Confirm a stored password hash starts with `$2y$`: `mysql -u root find_a_buddy -e "SELECT password_hash FROM users LIMIT 1;"`.

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "test: complete manual end-to-end verification pass" --allow-empty
```
