<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/planner.php';

class PlannerTest extends TestCase
{
    // ---- time helpers ----

    public function test_parse_hhmm(): void
    {
        $this->assertSame(570, parse_hhmm('09:30'));
        $this->assertSame(0, parse_hhmm('00:00'));
        $this->assertSame(1439, parse_hhmm('23:59'));
        foreach (['9:30', '24:00', '12:60', 'noon', '', null, 930] as $bad) {
            $this->assertNull(parse_hhmm($bad), var_export($bad, true));
        }
    }

    public function test_format_hhmm(): void
    {
        $this->assertSame('09:30', format_hhmm(570));
        $this->assertSame('00:00', format_hhmm(0));
    }

    // ---- input validation ----

    private function block(array $o = []): array
    {
        return array_merge(['day' => 1, 'start' => '10:00', 'end' => '11:15', 'course_id' => 5, 'label' => ''], $o);
    }

    public function test_valid_block(): void
    {
        [$clean, $errors] = clean_block_input($this->block());
        $this->assertSame([], $errors);
        $this->assertSame(1, $clean['day']);
        $this->assertSame(600, $clean['start_min']);
        $this->assertSame(675, $clean['end_min']);
        $this->assertSame(5, $clean['course_id']);
    }

    public function test_block_needs_end_after_start(): void
    {
        [, $errors] = clean_block_input($this->block(['start' => '11:00', 'end' => '10:00']));
        $this->assertArrayHasKey('end', $errors);
        [, $errors] = clean_block_input($this->block(['start' => '11:00', 'end' => '11:10']));
        $this->assertArrayHasKey('end', $errors);
    }

    public function test_block_rejects_bad_day_and_times(): void
    {
        foreach ([-1, 7, 'x', null] as $bad) {
            [, $errors] = clean_block_input($this->block(['day' => $bad]));
            $this->assertArrayHasKey('day', $errors, var_export($bad, true));
        }
        [, $errors] = clean_block_input($this->block(['start' => '25:00']));
        $this->assertArrayHasKey('start', $errors);
    }

    public function test_block_without_course_needs_a_label(): void
    {
        [, $errors] = clean_block_input($this->block(['course_id' => 0, 'label' => '  ']));
        $this->assertArrayHasKey('label', $errors);
        [$clean, $errors] = clean_block_input($this->block(['course_id' => 0, 'label' => ' Work ']));
        $this->assertSame([], $errors);
        $this->assertSame('Work', $clean['label']);
        $this->assertNull($clean['course_id']);
    }

    public function test_long_label_is_capped(): void
    {
        [$clean] = clean_block_input($this->block(['course_id' => 0, 'label' => str_repeat('a', 200)]));
        $this->assertSame(60, mb_strlen($clean['label']));
    }

    public function test_valid_prefs(): void
    {
        [$clean, $errors] = clean_planner_prefs(['hours' => 8, 'day_start' => '09:00', 'day_end' => '21:30']);
        $this->assertSame([], $errors);
        $this->assertSame(8, $clean['hours']);
        $this->assertSame(540, $clean['day_start']);
        $this->assertSame(1290, $clean['day_end']);
    }

    public function test_prefs_reject_bad_hours_and_windows(): void
    {
        foreach ([0, 41, 'x', null] as $bad) {
            [, $errors] = clean_planner_prefs(['hours' => $bad, 'day_start' => '09:00', 'day_end' => '21:00']);
            $this->assertArrayHasKey('hours', $errors, var_export($bad, true));
        }
        [, $errors] = clean_planner_prefs(['hours' => 5, 'day_start' => '09:00', 'day_end' => '10:30']);
        $this->assertArrayHasKey('day_end', $errors);
        [, $errors] = clean_planner_prefs(['hours' => 5, 'day_start' => '03:00', 'day_end' => '12:00']);
        $this->assertArrayHasKey('day_start', $errors);
    }

    // ---- free time ----

    public function test_whole_window_is_free_with_nothing_busy(): void
    {
        $this->assertSame([[480, 720]], free_intervals([], 480, 720));
    }

    public function test_free_time_leaves_a_buffer_around_busy_blocks(): void
    {
        $this->assertSame([[480, 525], [615, 720]], free_intervals([[540, 600]], 480, 720));
    }

    public function test_overlapping_busy_blocks_are_merged(): void
    {
        $this->assertSame([[480, 525], [665, 720]], free_intervals([[540, 600], [590, 650]], 480, 720));
    }

    public function test_gaps_shorter_than_30_minutes_are_dropped(): void
    {
        $this->assertSame([], free_intervals([[500, 700]], 480, 720));
    }

    public function test_busy_time_outside_the_window_is_ignored(): void
    {
        $this->assertSame([[480, 720]], free_intervals([[0, 300], [900, 1000]], 480, 720));
    }

    // ---- weights and allocation ----

    public function test_failing_courses_weigh_more(): void
    {
        $w = course_weights([
            ['id' => 1, 'progress' => 'failing'],
            ['id' => 2, 'progress' => null],
            ['id' => 3, 'progress' => 'passing'],
        ]);
        $this->assertSame([1 => 3, 2 => 2, 3 => 1], $w);
    }

    public function test_allocation_follows_weights_in_30_minute_steps(): void
    {
        $this->assertSame([1 => 450, 2 => 150], allocate_minutes([1 => 3, 2 => 1], 600));
    }

    public function test_allocation_always_sums_to_the_total_rounded_to_30(): void
    {
        $a = allocate_minutes([1 => 1, 2 => 1, 3 => 1], 600);
        $this->assertSame(600, array_sum($a));
        foreach ($a as $m) {
            $this->assertSame(0, $m % 30);
        }
        $this->assertSame(600, array_sum(allocate_minutes([1 => 1, 2 => 1, 3 => 1], 619)));
    }

    public function test_allocation_with_no_courses_is_empty(): void
    {
        $this->assertSame([], allocate_minutes([], 600));
    }

    // ---- the plan ----

    private function assertPlanIsSane(array $plan, array $busyByDay, int $start, int $end): void
    {
        $byDay = [];
        foreach ($plan['blocks'] as $b) {
            $len = $b['end'] - $b['start'];
            $this->assertGreaterThanOrEqual(30, $len);
            $this->assertLessThanOrEqual(90, $len);
            $this->assertSame(0, $len % 30);
            $this->assertGreaterThanOrEqual($start, $b['start']);
            $this->assertLessThanOrEqual($end, $b['end']);
            foreach ($busyByDay[$b['day']] ?? [] as [$bs, $be]) {
                $this->assertTrue($b['start'] >= $be + 15 || $b['end'] + 15 <= $bs, 'study block too close to a busy block');
            }
            $byDay[$b['day']][] = $b;
        }
        foreach ($byDay as $blocks) {
            usort($blocks, fn($x, $y) => $x['start'] <=> $y['start']);
            for ($i = 1; $i < count($blocks); $i++) {
                $this->assertGreaterThanOrEqual($blocks[$i - 1]['end'] + 15, $blocks[$i]['start'], 'blocks overlap');
            }
        }
    }

    public function test_no_courses_means_an_empty_plan(): void
    {
        $plan = generate_plan([], [], 600, 480, 1260);
        $this->assertSame([], $plan['blocks']);
        $this->assertSame(0, $plan['unplaced_minutes']);
    }

    public function test_free_week_gets_all_the_requested_time(): void
    {
        $courses = [['id' => 1, 'progress' => 'failing'], ['id' => 2, 'progress' => 'passing'], ['id' => 3, 'progress' => null]];
        $plan = generate_plan([], $courses, 600, 480, 1260);
        $this->assertSame(600, array_sum($plan['planned']));
        $this->assertSame(0, $plan['unplaced_minutes']);
        $this->assertPlanIsSane($plan, [], 480, 1260);
    }

    public function test_failing_course_gets_more_time_than_a_passing_one(): void
    {
        $courses = [['id' => 1, 'progress' => 'failing'], ['id' => 2, 'progress' => 'passing']];
        $plan = generate_plan([], $courses, 600, 480, 1260);
        $this->assertGreaterThan($plan['planned'][2], $plan['planned'][1]);
    }

    public function test_plan_stays_clear_of_classes(): void
    {
        $busy = [
            0 => [[540, 615], [780, 855]],
            1 => [[600, 675]],
            2 => [[540, 615], [780, 855]],
            3 => [[600, 675]],
            4 => [[540, 615]],
        ];
        $courses = [['id' => 1, 'progress' => 'failing'], ['id' => 2, 'progress' => null]];
        $plan = generate_plan($busy, $courses, 480, 480, 1260);
        $this->assertPlanIsSane($plan, $busy, 480, 1260);
        $this->assertSame(480, array_sum($plan['planned']));
    }

    public function test_a_fully_busy_week_leaves_everything_unplaced(): void
    {
        $busy = [];
        for ($d = 0; $d < 7; $d++) {
            $busy[$d] = [[480, 1260]];
        }
        $plan = generate_plan($busy, [['id' => 1, 'progress' => null]], 300, 480, 1260);
        $this->assertSame([], $plan['blocks']);
        $this->assertSame(300, $plan['unplaced_minutes']);
    }

    public function test_a_busy_day_gets_no_study_blocks(): void
    {
        $busy = [0 => [[480, 1260]]];
        $plan = generate_plan($busy, [['id' => 1, 'progress' => null]], 600, 480, 1260);
        foreach ($plan['blocks'] as $b) {
            $this->assertNotSame(0, $b['day']);
        }
    }

    public function test_one_course_is_spread_over_different_days(): void
    {
        $plan = generate_plan([], [['id' => 1, 'progress' => null]], 180, 480, 1260);
        $days = array_column($plan['blocks'], 'day');
        $this->assertCount(2, $days);
        $this->assertCount(2, array_unique($days));
    }

    public function test_unplaced_minutes_are_reported_when_time_runs_out(): void
    {
        // Only Monday has any free time, and only 60 minutes of it.
        $busy = [];
        for ($d = 1; $d < 7; $d++) {
            $busy[$d] = [[480, 1260]];
        }
        $plan = generate_plan($busy, [['id' => 1, 'progress' => null]], 300, 480, 540);
        $this->assertSame(60, array_sum($plan['planned']));
        $this->assertSame(240, $plan['unplaced_minutes']);
    }

    public function test_a_full_block_goes_in_a_long_gap_not_a_short_one(): void
    {
        // Monday has a 45 minute gap before class (08:00-08:45) and a long free afternoon.
        $plan = generate_plan([0 => [[540, 600]]], [['id' => 1, 'progress' => null]], 90, 480, 1260);
        $this->assertCount(1, $plan['blocks']);
        $this->assertSame(0, $plan['blocks'][0]['day']);
        $this->assertSame(615, $plan['blocks'][0]['start']);
        $this->assertSame(705, $plan['blocks'][0]['end']);
    }

    public function test_the_short_gap_is_still_used_when_nothing_longer_exists(): void
    {
        // Only Monday 08:00-08:45 is free all week.
        $busy = [0 => [[540, 1260]]];
        for ($d = 1; $d < 7; $d++) {
            $busy[$d] = [[480, 1260]];
        }
        $plan = generate_plan($busy, [['id' => 1, 'progress' => null]], 90, 480, 1260);
        $this->assertSame(30, array_sum($plan['planned']));
        $this->assertSame(60, $plan['unplaced_minutes']);
    }

    public function test_plan_is_deterministic(): void
    {
        $courses = [['id' => 1, 'progress' => 'failing'], ['id' => 2, 'progress' => null]];
        $this->assertSame(
            generate_plan([1 => [[600, 660]]], $courses, 420, 480, 1260),
            generate_plan([1 => [[600, 660]]], $courses, 420, 480, 1260)
        );
    }
}
