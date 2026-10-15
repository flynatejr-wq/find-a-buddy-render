<?php

const PROFILE_YEARS = ['Freshman', 'Sophomore', 'Junior', 'Senior', 'Graduate'];
const PROFILE_TIMES = ['Mornings', 'Afternoons', 'Evenings', 'Weekends'];
const PROFILE_STYLES = ['Small group', 'One-on-one', 'Quiet / silent', 'Talk it through'];
const PROFILE_PLACES = ['Library', 'Student center', 'Online / Zoom', 'Dorm / off campus'];

/**
 * Validates and normalizes profile form input.
 * Returns [cleanFields, errorsByField].
 */
function clean_profile_input(array $input): array
{
    $errors = [];

    $major = trim((string) ($input['major'] ?? ''));
    if ($major === '') {
        $errors['major'] = 'Major is required.';
    }
    $major = mb_substr($major, 0, 100);

    $year = (string) ($input['year'] ?? '');
    if (!in_array($year, PROFILE_YEARS, true)) {
        $errors['year'] = 'Pick a valid year.';
    }

    $times = is_array($input['study_times'] ?? null) ? $input['study_times'] : [];
    $times = array_values(array_unique(array_filter($times, fn($t) => in_array($t, PROFILE_TIMES, true))));

    $style = (string) ($input['study_style'] ?? '');
    if (!in_array($style, PROFILE_STYLES, true)) {
        $errors['study_style'] = 'Pick a valid study style.';
    }

    $place = (string) ($input['study_place'] ?? '');
    if (!in_array($place, PROFILE_PLACES, true)) {
        $errors['study_place'] = 'Pick a valid study place.';
    }

    $help = mb_substr(trim((string) ($input['help_text'] ?? '')), 0, 500);

    return [[
        'major' => $major,
        'year' => $year,
        'study_times' => implode(', ', $times),
        'study_style' => $style,
        'study_place' => $place,
        'help_text' => $help,
    ], $errors];
}

/** Creates the profiles table if it doesn't exist yet (works on MySQL and Postgres). */
function ensure_profiles_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS profiles (
        user_id INT NOT NULL PRIMARY KEY,
        major VARCHAR(100) NOT NULL,
        year VARCHAR(20) NOT NULL,
        study_times VARCHAR(100) NOT NULL DEFAULT \'\',
        study_style VARCHAR(50) NOT NULL,
        study_place VARCHAR(50) NOT NULL,
        help_text VARCHAR(500) NOT NULL DEFAULT \'\',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
}
