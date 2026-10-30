<?php

const PLAN_STEP = 30;        // study time is planned in 30 minute steps
const PLAN_MIN_CHUNK = 30;
const PLAN_MAX_CHUNK = 90;   // longest single study block
const PLAN_BUFFER = 15;      // breathing room before/after classes and between study blocks
const PLAN_DEFAULT_HOURS = 10;
const PLAN_DEFAULT_START = 480;   // 08:00
const PLAN_DEFAULT_END = 1260;    // 21:00
const PLAN_MAX_BLOCKS = 60;
const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/** 'HH:MM' (24 hour) to minutes after midnight, or null if it is not a valid time. */
function parse_hhmm($value): ?int
{
    if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

function format_hhmm(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** A weekday as 0 (Monday) to 6 (Sunday), from a number or a name like "Mon" or "wednesday". Null if unclear. */
function parse_day($value): ?int
{
    if (is_int($value) || (is_string($value) && preg_match('/^[0-6]$/', trim($value)))) {
        $n = (int) $value;
        return $n >= 0 && $n <= 6 ? $n : null;
    }
    if (!is_string($value)) {
        return null;
    }
    $names = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
    $key = strtolower(substr(trim($value), 0, 3));
    $full = strtolower(trim($value));
    $isName = in_array($full, ['mon', 'monday', 'tue', 'tues', 'tuesday', 'wed', 'wednesday', 'thu', 'thur', 'thurs', 'thursday', 'fri', 'friday', 'sat', 'saturday', 'sun', 'sunday'], true);
    return $isName ? $names[$key] : null;
}

/**
 * Validates a class or busy block. Returns [cleanFields, errorsByField].
 * course_id 0 or missing means "not a course" (work, a commute, and so on), which needs a label.
 */
function clean_block_input(array $input): array
{
    $errors = [];

    $day = $input['day'] ?? null;
    if (!is_numeric($day) || (int) $day != $day || $day < 0 || $day > 6) {
        $errors['day'] = 'Pick a day.';
    }

    $start = parse_hhmm($input['start'] ?? null);
    $end = parse_hhmm($input['end'] ?? null);
    if ($start === null) {
        $errors['start'] = 'Pick a start time.';
    }
    if ($end === null) {
        $errors['end'] = 'Pick an end time.';
    } elseif ($start !== null && $end - $start < 15) {
        $errors['end'] = 'It must end at least 15 minutes after it starts.';
    }

    $courseId = (int) ($input['course_id'] ?? 0);
    $label = mb_substr(trim((string) ($input['label'] ?? '')), 0, 60);
    if ($courseId <= 0 && $label === '') {
        $errors['label'] = 'Say what this is, for example Work.';
    }

    return [[
        'day' => (int) $day,
        'start_min' => (int) $start,
        'end_min' => (int) $end,
        'course_id' => $courseId > 0 ? $courseId : null,
        'label' => $courseId > 0 ? '' : $label,
    ], $errors];
}

/** Validates the study goal and the daily window. Returns [cleanFields, errorsByField]. */
function clean_planner_prefs(array $input): array
{
    $errors = [];

    $hours = $input['hours'] ?? null;
    if (!is_numeric($hours) || (int) $hours != $hours || $hours < 1 || $hours > 40) {
        $errors['hours'] = 'Choose between 1 and 40 hours a week.';
    }

    $start = parse_hhmm($input['day_start'] ?? null);
    $end = parse_hhmm($input['day_end'] ?? null);
    if ($start === null || $start < 300) {
        $errors['day_start'] = 'Pick an earliest time of 5:00 AM or later.';
    }
    if ($end === null) {
        $errors['day_end'] = 'Pick a latest time.';
    } elseif ($start !== null && $end - $start < 120) {
        $errors['day_end'] = 'Leave at least 2 hours between earliest and latest.';
    }

    return [[
        'hours' => (int) $hours,
        'day_start' => (int) $start,
        'day_end' => (int) $end,
    ], $errors];
}

/**
 * Free stretches of at least 30 minutes inside [$dayStart, $dayEnd], with a buffer kept
 * around every busy block. $busy is a list of [startMinute, endMinute].
 */
function free_intervals(array $busy, int $dayStart, int $dayEnd): array
{
    $padded = [];
    foreach ($busy as [$s, $e]) {
        $padded[] = [$s - PLAN_BUFFER, $e + PLAN_BUFFER];
    }
    usort($padded, fn($a, $b) => $a[0] <=> $b[0]);

    $merged = [];
    foreach ($padded as [$s, $e]) {
        if ($merged && $s <= $merged[count($merged) - 1][1]) {
            $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $e);
        } else {
            $merged[] = [$s, $e];
        }
    }

    $free = [];
    $cursor = $dayStart;
    foreach ($merged as [$s, $e]) {
        if ($s > $cursor && min($s, $dayEnd) - $cursor >= PLAN_MIN_CHUNK) {
            $free[] = [$cursor, min($s, $dayEnd)];
        }
        $cursor = max($cursor, $e);
    }
    if ($dayEnd - $cursor >= PLAN_MIN_CHUNK) {
        $free[] = [$cursor, $dayEnd];
    }
    return $free;
}

/** course id => weight. Failing courses get the most study time, passing the least. */
function course_weights(array $courses): array
{
    $weights = [];
    foreach ($courses as $c) {
        $weights[(int) $c['id']] = match ($c['progress'] ?? null) {
            'failing' => 3,
            'passing' => 1,
            default => 2,
        };
    }
    return $weights;
}

/** Splits $totalMinutes between courses by weight, in 30 minute steps, summing exactly. */
function allocate_minutes(array $weights, int $totalMinutes): array
{
    if (!$weights) {
        return [];
    }
    $steps = intdiv($totalMinutes, PLAN_STEP);
    $sum = array_sum($weights);

    $alloc = [];
    $remainders = [];
    $given = 0;
    foreach ($weights as $id => $w) {
        $exact = $steps * $w / $sum;
        $alloc[$id] = (int) floor($exact);
        $remainders[$id] = $exact - $alloc[$id];
        $given += $alloc[$id];
    }

    // Hand out the leftover steps to the largest remainders (ties go to the lower id).
    $ids = array_keys($weights);
    usort($ids, fn($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
    for ($i = 0; $given < $steps; $i++, $given++) {
        $alloc[$ids[$i % count($ids)]]++;
    }

    return array_map(fn($n) => $n * PLAN_STEP, $alloc);
}

/**
 * Builds a weekly study plan around the busy blocks.
 *   $busyByDay: day (0 = Monday .. 6 = Sunday) => list of [startMinute, endMinute]
 *   $courses:   list of ['id' => int, 'progress' => 'passing'|'failing'|null]
 * The course with the most time still to place goes first, onto the day with the least study
 * so far, so time is spread across the week and one course is kept to one block a day where it can.
 * Returns blocks, wanted and planned minutes per course, and the minutes that did not fit.
 */
function generate_plan(array $busyByDay, array $courses, int $minutesPerWeek, int $dayStart, int $dayEnd): array
{
    $wanted = allocate_minutes(course_weights($courses), $minutesPerWeek);
    $remaining = $wanted;
    $planned = array_fill_keys(array_keys($wanted), 0);

    $free = [];
    for ($d = 0; $d < 7; $d++) {
        $free[$d] = free_intervals($busyByDay[$d] ?? [], $dayStart, $dayEnd);
    }
    $dayMinutes = array_fill(0, 7, 0);
    $dayCourses = array_fill(0, 7, []);
    $blocks = [];
    $stuck = [];

    while (true) {
        // The course with the most time left to place (ties: lower id).
        $courseId = null;
        foreach ($remaining as $id => $m) {
            if ($m >= PLAN_MIN_CHUNK && !isset($stuck[$id]) && ($courseId === null || $m > $remaining[$courseId])) {
                $courseId = $id;
            }
        }
        if ($courseId === null) {
            break;
        }

        $placed = false;
        foreach ([true, false] as $avoidRepeat) {
            $days = range(0, 6);
            usort($days, fn($a, $b) => $dayMinutes[$a] <=> $dayMinutes[$b] ?: $a <=> $b);
            foreach ($days as $d) {
                if ($avoidRepeat && in_array($courseId, $dayCourses[$d], true)) {
                    continue;
                }
                if (!$free[$d]) {
                    continue;
                }
                // Use the earliest gap that holds the whole block; if none does, the longest gap.
                $want = min($remaining[$courseId], PLAN_MAX_CHUNK);
                $pick = null;
                foreach ($free[$d] as $i => [$gs, $ge]) {
                    if (intdiv($ge - $gs, PLAN_STEP) * PLAN_STEP >= $want) {
                        $pick = $i;
                        break;
                    }
                    if ($pick === null || $ge - $gs > $free[$d][$pick][1] - $free[$d][$pick][0]) {
                        $pick = $i;
                    }
                }
                [$s, $e] = $free[$d][$pick];
                $len = min($want, intdiv($e - $s, PLAN_STEP) * PLAN_STEP);

                $blocks[] = ['day' => $d, 'start' => $s, 'end' => $s + $len, 'course_id' => $courseId];
                $remaining[$courseId] -= $len;
                $planned[$courseId] += $len;
                $dayMinutes[$d] += $len;
                $dayCourses[$d][] = $courseId;

                $free[$d][$pick][0] = $s + $len + PLAN_BUFFER;
                if ($free[$d][$pick][1] - $free[$d][$pick][0] < PLAN_MIN_CHUNK) {
                    array_splice($free[$d], $pick, 1);
                }
                $placed = true;
                break 2;
            }
        }
        if (!$placed) {
            $stuck[$courseId] = true;
        }
    }

    usort($blocks, fn($a, $b) => $a['day'] <=> $b['day'] ?: $a['start'] <=> $b['start']);

    return [
        'blocks' => $blocks,
        'wanted' => $wanted,
        'planned' => $planned,
        'unplaced_minutes' => array_sum($remaining),
    ];
}

/** Creates the planner tables if they do not exist yet (MySQL and Postgres). */
function ensure_planner_tables(PDO $pdo): void
{
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS schedule_blocks (
        id $id,
        user_id INT NOT NULL,
        course_id INT NULL,
        label VARCHAR(60) NOT NULL DEFAULT '',
        day SMALLINT NOT NULL,
        start_min SMALLINT NOT NULL,
        end_min SMALLINT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE TABLE IF NOT EXISTS planner_settings (
        user_id INT NOT NULL PRIMARY KEY,
        hours SMALLINT NOT NULL,
        day_start SMALLINT NOT NULL,
        day_end SMALLINT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
}
