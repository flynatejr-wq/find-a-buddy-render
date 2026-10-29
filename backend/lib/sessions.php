<?php

const SESSION_PLACES = ['Library', 'Student center', 'Online / Zoom', 'Dorm / off campus'];

/** Today's date on campus (Savannah is Eastern time), as YYYY-MM-DD. */
function campus_today(): string
{
    return (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
}

/**
 * Validates a proposed study session. Returns [cleanFields, errorsByField].
 * $today is passed in so the rule "not in the past" can be tested.
 */
function clean_session_input(array $input, string $today): array
{
    $errors = [];

    $topic = mb_substr(trim((string) ($input['topic'] ?? '')), 0, 100);
    if ($topic === '') {
        $errors['topic'] = 'Say what you will study.';
    }

    $date = (string) ($input['session_date'] ?? '');
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        $errors['session_date'] = 'Pick a valid date.';
    } elseif ($date < $today) {
        $errors['session_date'] = 'Pick today or a future date.';
    } elseif ($date > date('Y-m-d', strtotime($today . ' +1 year'))) {
        $errors['session_date'] = 'Pick a date within the next year.';
    }

    $time = (string) ($input['session_time'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $errors['session_time'] = 'Pick a valid time.';
    }

    $place = (string) ($input['place'] ?? '');
    if (!in_array($place, SESSION_PLACES, true)) {
        $errors['place'] = 'Pick a place.';
    }

    $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 200);

    return [[
        'topic' => $topic,
        'session_date' => $date,
        'session_time' => $time,
        'place' => $place,
        'note' => $note,
    ], $errors];
}

/**
 * The new status for a session after someone acts on it, or null if not allowed.
 * accept/decline: only the person who was asked, while it is still proposed.
 * cancel: either person, while proposed or accepted.
 */
function session_next_status($action, bool $isProposer, string $status): ?string
{
    if (!is_string($action)) {
        return null;
    }
    if ($action === 'accept' || $action === 'decline') {
        if ($isProposer || $status !== 'proposed') {
            return null;
        }
        return $action === 'accept' ? 'accepted' : 'declined';
    }
    if ($action === 'cancel') {
        return in_array($status, ['proposed', 'accepted'], true) ? 'cancelled' : null;
    }
    return null;
}

function ensure_sessions_table(PDO $pdo): void
{
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS study_sessions (
        id $id,
        proposer_id INT NOT NULL,
        buddy_id INT NOT NULL,
        topic VARCHAR(100) NOT NULL,
        session_date VARCHAR(10) NOT NULL,
        session_time VARCHAR(5) NOT NULL,
        place VARCHAR(50) NOT NULL,
        note VARCHAR(200) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'proposed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (proposer_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (buddy_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}
