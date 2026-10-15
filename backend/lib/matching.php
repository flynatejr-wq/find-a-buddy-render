<?php

function shared_course_ids(array $courseIdsA, array $courseIdsB): array
{
    return array_values(array_intersect($courseIdsA, $courseIdsB));
}

/**
 * Compatibility score, 0-100. Needs at least one shared course.
 *   courses:      40 for the first shared course, +10 for each extra (max 60)
 *   availability: 5 per overlapping time slot (max 25)
 *   study style:  +10 if the same
 *   study place:  +5 if the same
 */
function match_score(int $sharedCourses, int $overlappingSlots, bool $sameStyle, bool $samePlace): int
{
    if ($sharedCourses < 1) {
        return 0;
    }
    $score = min(60, 40 + 10 * ($sharedCourses - 1));
    $score += min(25, 5 * $overlappingSlots);
    $score += $sameStyle ? 10 : 0;
    $score += $samePlace ? 5 : 0;
    return $score;
}
