<?php

function shared_course_ids(array $courseIdsA, array $courseIdsB): array
{
    return array_values(array_intersect($courseIdsA, $courseIdsB));
}

/** Course ids that are in both lists, e.g. courses one student is failing and the other has passed. */
function help_courses(array $needHelpIds, array $passedIds): array
{
    return array_values(array_intersect($needHelpIds, $passedIds));
}

/**
 * Compatibility score, 0-100.
 *   courses:      40 for the first shared course, +10 for each extra (max 60).
 *                 With no shared course, a tutoring link alone starts at 30.
 *   availability: 5 per overlapping time slot (max 25)
 *   study style:  +10 if the same
 *   study place:  +5 if the same
 *   tutoring:     +25 if they passed a course I am failing, +10 if I passed one they are failing
 * Without a shared course or a tutoring link the score is 0.
 */
function match_score(
    int $sharedCourses,
    int $overlappingSlots,
    bool $sameStyle,
    bool $samePlace,
    bool $canHelpMe = false,
    bool $canIHelp = false
): int {
    if ($sharedCourses >= 1) {
        $score = min(60, 40 + 10 * ($sharedCourses - 1));
    } elseif ($canHelpMe || $canIHelp) {
        $score = 30;
    } else {
        return 0;
    }
    $score += min(25, 5 * $overlappingSlots);
    $score += $sameStyle ? 10 : 0;
    $score += $samePlace ? 5 : 0;
    $score += $canHelpMe ? 25 : 0;
    $score += $canIHelp ? 10 : 0;
    return min(100, $score);
}
