<?php

namespace Tests\Feature\Study;

use Tests\TestCase;

/** The order the job's safety rests on: Timeout < Lock < retry_after, and systemd waits longer than a turn. */
class StudyOpsConfigTest extends TestCase
{
    public function test_a_live_turn_is_never_taken_over(): void
    {
        $timeout = config('study.run.turn_timeout');

        $this->assertLessThan(config('study.run.lock_seconds'), $timeout);
        $this->assertLessThan(config('study.queue.retry_after'), config('study.run.lock_seconds'));
        $this->assertSame(config('study.queue.retry_after'), config('queue.connections.study.retry_after'));
    }

    public function test_systemd_lets_a_running_turn_finish_on_stop(): void
    {
        $unit = file_get_contents(base_path('app/Study/systemd/communicat-study@.service'));

        $this->assertMatchesRegularExpression('/^TimeoutStopSec=(\d+)$/m', $unit);
        preg_match('/^TimeoutStopSec=(\d+)$/m', $unit, $match);
        $this->assertGreaterThan(config('study.run.turn_timeout'), (int) $match[1]);
    }
}
