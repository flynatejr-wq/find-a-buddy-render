<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/wrapup.php';

class WrapupTest extends TestCase
{
    private function clean(array $courses, $clear = false, array $mine = [1, 2, 3]): array
    {
        return clean_wrapup_input(['courses' => $courses, 'clear_schedule' => $clear], $mine);
    }

    public function test_a_passing_grade_with_an_answer_is_accepted(): void
    {
        [$clean, $errors] = $this->clean([['course_id' => 1, 'result' => 'b', 'available' => 'yes']]);
        $this->assertSame([], $errors);
        $this->assertSame([['course_id' => 1, 'result' => 'B', 'available' => true]], $clean['courses']);
        $this->assertFalse($clean['clear_schedule']);
    }

    public function test_a_passing_grade_needs_the_buddy_answer(): void
    {
        [, $errors] = $this->clean([['course_id' => 1, 'result' => 'A']]);
        $this->assertArrayHasKey(1, $errors);
    }

    public function test_a_failing_grade_never_offers_help(): void
    {
        [$clean, $errors] = $this->clean([['course_id' => 2, 'result' => 'F', 'available' => 'yes']]);
        $this->assertSame([], $errors);
        $this->assertFalse($clean['courses'][0]['available']);
    }

    public function test_keep_and_drop_are_accepted_without_an_answer(): void
    {
        [$clean, $errors] = $this->clean([['course_id' => 1, 'result' => 'keep'], ['course_id' => 2, 'result' => 'drop']]);
        $this->assertSame([], $errors);
        $this->assertSame(['keep', 'drop'], array_column($clean['courses'], 'result'));
    }

    public function test_a_missing_or_unknown_result_is_rejected(): void
    {
        foreach (['', 'E', 'maybe', null, ['A']] as $bad) {
            [, $errors] = $this->clean([['course_id' => 1, 'result' => $bad]]);
            $this->assertArrayHasKey(1, $errors, var_export($bad, true));
        }
    }

    public function test_only_my_current_courses_can_be_finished(): void
    {
        [, $errors] = $this->clean([['course_id' => 99, 'result' => 'F']]);
        $this->assertArrayHasKey('courses', $errors);
    }

    public function test_the_same_course_cannot_be_listed_twice(): void
    {
        [, $errors] = $this->clean([['course_id' => 1, 'result' => 'F'], ['course_id' => 1, 'result' => 'drop']]);
        $this->assertArrayHasKey('courses', $errors);
    }

    public function test_nothing_to_do_is_rejected(): void
    {
        [, $errors] = $this->clean([]);
        $this->assertArrayHasKey('courses', $errors);
        [, $errors] = $this->clean([['course_id' => 1, 'result' => 'keep']]);
        $this->assertArrayHasKey('courses', $errors);
    }

    public function test_clearing_the_schedule_alone_is_allowed(): void
    {
        [$clean, $errors] = $this->clean([], true, []);
        $this->assertSame([], $errors);
        $this->assertTrue($clean['clear_schedule']);
    }

    public function test_garbage_input_is_rejected_not_crashed_on(): void
    {
        [, $errors] = clean_wrapup_input(['courses' => 'x'], [1]);
        $this->assertArrayHasKey('courses', $errors);
        [, $errors] = clean_wrapup_input(['courses' => ['x']], [1]);
        $this->assertArrayHasKey('courses', $errors);
    }
}
