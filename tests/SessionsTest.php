<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/sessions.php';

class SessionsTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'topic' => 'Calculus exam review',
            'session_date' => '2026-11-10',
            'session_time' => '18:30',
            'place' => 'Library',
            'note' => 'Bring notes',
        ], $overrides);
    }

    public function test_valid_input_has_no_errors(): void
    {
        [$clean, $errors] = clean_session_input($this->valid(), '2026-11-01');
        $this->assertSame([], $errors);
        $this->assertSame('18:30', $clean['session_time']);
    }

    public function test_today_is_allowed(): void
    {
        [, $errors] = clean_session_input($this->valid(['session_date' => '2026-11-01']), '2026-11-01');
        $this->assertSame([], $errors);
    }

    public function test_past_date_is_rejected(): void
    {
        [, $errors] = clean_session_input($this->valid(['session_date' => '2026-10-31']), '2026-11-01');
        $this->assertArrayHasKey('session_date', $errors);
    }

    public function test_date_more_than_a_year_away_is_rejected(): void
    {
        [, $errors] = clean_session_input($this->valid(['session_date' => '2027-11-02']), '2026-11-01');
        $this->assertArrayHasKey('session_date', $errors);
    }

    public function test_impossible_or_malformed_dates_are_rejected(): void
    {
        foreach (['2026-02-30', 'tomorrow', '', '11/10/2026'] as $bad) {
            [, $errors] = clean_session_input($this->valid(['session_date' => $bad]), '2026-11-01');
            $this->assertArrayHasKey('session_date', $errors, $bad);
        }
    }

    public function test_bad_times_are_rejected(): void
    {
        foreach (['24:00', '9:30', '12:60', 'noon', ''] as $bad) {
            [, $errors] = clean_session_input($this->valid(['session_time' => $bad]), '2026-11-01');
            $this->assertArrayHasKey('session_time', $errors, $bad);
        }
    }

    public function test_topic_required_and_unknown_place_rejected(): void
    {
        [, $errors] = clean_session_input($this->valid(['topic' => '  ', 'place' => 'The moon']), '2026-11-01');
        $this->assertArrayHasKey('topic', $errors);
        $this->assertArrayHasKey('place', $errors);
    }

    public function test_long_topic_and_note_are_capped(): void
    {
        [$clean] = clean_session_input($this->valid(['topic' => str_repeat('a', 300), 'note' => str_repeat('b', 500)]), '2026-11-01');
        $this->assertSame(100, mb_strlen($clean['topic']));
        $this->assertSame(200, mb_strlen($clean['note']));
    }

    public function test_receiver_can_accept_or_decline_a_proposal(): void
    {
        $this->assertSame('accepted', session_next_status('accept', false, 'proposed'));
        $this->assertSame('declined', session_next_status('decline', false, 'proposed'));
    }

    public function test_proposer_cannot_accept_their_own_proposal(): void
    {
        $this->assertNull(session_next_status('accept', true, 'proposed'));
        $this->assertNull(session_next_status('decline', true, 'proposed'));
    }

    public function test_cannot_accept_after_it_is_already_answered(): void
    {
        $this->assertNull(session_next_status('accept', false, 'accepted'));
        $this->assertNull(session_next_status('decline', false, 'cancelled'));
    }

    public function test_either_person_can_cancel_a_live_session(): void
    {
        $this->assertSame('cancelled', session_next_status('cancel', true, 'proposed'));
        $this->assertSame('cancelled', session_next_status('cancel', false, 'accepted'));
    }

    public function test_cannot_cancel_a_finished_session(): void
    {
        $this->assertNull(session_next_status('cancel', true, 'declined'));
        $this->assertNull(session_next_status('cancel', true, 'cancelled'));
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->assertNull(session_next_status('delete', false, 'proposed'));
        $this->assertNull(session_next_status(null, false, 'proposed'));
    }
}
