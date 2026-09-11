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
