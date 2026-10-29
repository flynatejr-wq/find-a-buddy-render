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

    public function test_score_is_zero_with_no_shared_courses(): void
    {
        $this->assertSame(0, match_score(0, 5, true, true));
    }

    public function test_one_shared_course_alone_scores_forty(): void
    {
        $this->assertSame(40, match_score(1, 0, false, false));
    }

    public function test_extra_shared_courses_add_ten_each_up_to_sixty(): void
    {
        $this->assertSame(50, match_score(2, 0, false, false));
        $this->assertSame(60, match_score(3, 0, false, false));
        $this->assertSame(60, match_score(9, 0, false, false));
    }

    public function test_availability_adds_five_per_slot_capped_at_twenty_five(): void
    {
        $this->assertSame(55, match_score(2, 1, false, false));
        $this->assertSame(65, match_score(1, 5, false, false));
        $this->assertSame(65, match_score(1, 12, false, false));
    }

    public function test_same_style_and_place_add_ten_and_five(): void
    {
        $this->assertSame(50, match_score(1, 0, true, false));
        $this->assertSame(45, match_score(1, 0, false, true));
    }

    public function test_perfect_match_is_one_hundred(): void
    {
        $this->assertSame(100, match_score(3, 5, true, true));
    }

    public function test_help_courses_is_the_overlap(): void
    {
        $this->assertSame([2, 5], help_courses([1, 2, 5], [2, 5, 9]));
        $this->assertSame([], help_courses([1], [2]));
        $this->assertSame([], help_courses([], [2]));
    }

    public function test_someone_who_can_help_me_adds_twenty_five(): void
    {
        $this->assertSame(65, match_score(1, 0, false, false, true, false));
    }

    public function test_someone_i_can_help_adds_ten(): void
    {
        $this->assertSame(50, match_score(1, 0, false, false, false, true));
    }

    public function test_tutor_only_match_without_shared_course_has_a_base_score(): void
    {
        $this->assertSame(55, match_score(0, 0, false, false, true, false));
        $this->assertSame(40, match_score(0, 0, false, false, false, true));
    }

    public function test_no_shared_course_and_no_help_relation_is_still_zero(): void
    {
        $this->assertSame(0, match_score(0, 5, true, true, false, false));
    }

    public function test_score_never_exceeds_one_hundred(): void
    {
        $this->assertSame(100, match_score(5, 9, true, true, true, true));
    }
}
