<?php

const LETTER_GRADES = ['A', 'B', 'C', 'D', 'F'];
// A "passed" course means a grade of C or better.
const PASSING_GRADES = ['A', 'B', 'C'];
const PROGRESS_VALUES = ['passing', 'failing'];

/** Returns an uppercase letter grade (A-F), or null if the input is not one. */
function clean_grade($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = strtoupper(trim($value));
    return in_array($value, LETTER_GRADES, true) ? $value : null;
}

function grade_is_passing(string $grade): bool
{
    return in_array($grade, PASSING_GRADES, true);
}

/** Returns 'passing' or 'failing', or null if the input is neither. */
function clean_progress($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = strtolower(trim($value));
    return in_array($value, PROGRESS_VALUES, true) ? $value : null;
}

/** Reads a clear yes/no answer ("are you available to help?"). Returns null if it is not clearly one. */
function parse_available_flag($value): ?bool
{
    if ($value === true || $value === 1 || $value === '1') {
        return true;
    }
    if ($value === false || $value === 0 || $value === '0') {
        return false;
    }
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if ($v === 'yes' || $v === 'true') {
            return true;
        }
        if ($v === 'no' || $v === 'false') {
            return false;
        }
    }
    return null;
}

/** Adds a column to an existing table if it is missing (for tables created by an earlier version). */
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $schema = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'current_schema()' : 'DATABASE()';
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = $schema AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

/** Creates the course progress and grade tables if they do not exist yet (MySQL and Postgres). */
function ensure_standing_tables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS course_progress (
        user_id INT NOT NULL,
        course_id INT NOT NULL,
        status VARCHAR(10) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, course_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS course_grades (
        user_id INT NOT NULL,
        course_id INT NOT NULL,
        grade CHAR(1) NOT NULL,
        available_to_help SMALLINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, course_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    )');
    // Tables made before the opt-in question existed: nobody is available until they say so.
    ensure_column($pdo, 'course_grades', 'available_to_help', 'SMALLINT NOT NULL DEFAULT 0');
}
