<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/grades.php';

class GradesTest extends TestCase
{
    public function test_valid_letter_grades_are_accepted(): void
    {
        foreach (['A', 'B', 'C', 'D', 'F'] as $g) {
            $this->assertSame($g, clean_grade($g));
        }
    }

    public function test_grade_is_trimmed_and_uppercased(): void
    {
        $this->assertSame('B', clean_grade(' b '));
    }

    public function test_invalid_grades_are_rejected(): void
    {
        foreach (['E', 'A+', '', 'AB', null, 4, ['A']] as $bad) {
            $this->assertNull(clean_grade($bad), var_export($bad, true));
        }
    }

    public function test_c_or_better_counts_as_passed(): void
    {
        $this->assertTrue(grade_is_passing('A'));
        $this->assertTrue(grade_is_passing('B'));
        $this->assertTrue(grade_is_passing('C'));
        $this->assertFalse(grade_is_passing('D'));
        $this->assertFalse(grade_is_passing('F'));
    }

    public function test_progress_accepts_only_passing_or_failing(): void
    {
        $this->assertSame('passing', clean_progress('passing'));
        $this->assertSame('failing', clean_progress(' FAILING '));
        $this->assertNull(clean_progress('moderate'));
        $this->assertNull(clean_progress(''));
        $this->assertNull(clean_progress(null));
    }

    public function test_passing_grades_constant_matches_rule(): void
    {
        $this->assertSame(['A', 'B', 'C'], PASSING_GRADES);
    }
}
