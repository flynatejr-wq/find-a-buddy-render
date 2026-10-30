<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/courses.php';
require_once __DIR__ . '/../backend/lib/planner.php';

class CoursesTest extends TestCase
{
    public function test_course_codes_are_normalized(): void
    {
        $this->assertSame('CSCI 2231K', normalize_course_code('  csci   2231k '));
        $this->assertSame('MATH 1501', normalize_course_code("math\t1501"));
        $this->assertSame('', normalize_course_code('   '));
    }

    public function test_days_can_be_given_as_names_or_numbers(): void
    {
        $this->assertSame(0, parse_day('Monday'));
        $this->assertSame(0, parse_day('mon'));
        $this->assertSame(2, parse_day(' WEDNESDAY '));
        $this->assertSame(3, parse_day('Thurs'));
        $this->assertSame(6, parse_day('sun'));
        $this->assertSame(4, parse_day(4));
        $this->assertSame(5, parse_day('5'));
    }

    public function test_unclear_days_are_rejected(): void
    {
        foreach (['someday', '', 'm', 7, -1, '9', null, ['mon'], 'monkey', 'tues day'] as $bad) {
            $this->assertNull(parse_day($bad), var_export($bad, true));
        }
    }
}
