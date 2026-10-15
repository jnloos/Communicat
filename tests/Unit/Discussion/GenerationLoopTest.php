<?php

namespace Tests\Unit\Discussion;

use App\Discussion\GenerationLoop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GenerationLoopTest extends TestCase
{
    public function test_the_generating_flag_can_be_raised_and_cleared(): void
    {
        $loop = new GenerationLoop;

        $this->assertFalse($loop->isGenerating(1));

        $loop->start(1);
        $this->assertTrue($loop->isGenerating(1));
        $this->assertFalse($loop->isGenerating(2));

        $loop->stop(1);
        $this->assertFalse($loop->isGenerating(1));
    }

    public function test_viewer_presence_is_tracked_per_project(): void
    {
        $loop = new GenerationLoop;

        $loop->markViewing(1);

        $this->assertTrue($loop->hasViewers(1));
        $this->assertFalse($loop->hasViewers(2));
    }

    public function test_a_turn_counts_as_running_while_the_lock_is_held(): void
    {
        $loop = new GenerationLoop;
        $seenInside = null;

        $loop->withLock(1, function () use ($loop, &$seenInside) {
            $seenInside = $loop->isTurnRunning(1);
        });

        $this->assertTrue($seenInside);
        $this->assertFalse($loop->isTurnRunning(1));
    }

    public function test_a_second_turn_does_not_start_while_the_lock_is_held(): void
    {
        $loop = new GenerationLoop;
        $innerRan = false;

        $loop->withLock(1, function () use ($loop, &$innerRan) {
            $loop->withLock(1, function () use (&$innerRan) {
                $innerRan = true;
            });
        });

        $this->assertFalse($innerRan);
    }

    public function test_asking_whether_a_turn_runs_does_not_take_the_lock(): void
    {
        Cache::spy();

        (new GenerationLoop)->isTurnRunning(1);

        Cache::shouldNotHaveReceived('lock');
    }

    public function test_the_running_flag_is_cleared_even_when_the_turn_throws(): void
    {
        $loop = new GenerationLoop;

        try {
            $loop->withLock(1, fn () => throw new \RuntimeException('kaputt'));
        } catch (\RuntimeException) {
        }

        $this->assertFalse($loop->isTurnRunning(1));

        $ran = false;
        $loop->withLock(1, function () use (&$ran) {
            $ran = true;
        });
        $this->assertTrue($ran);
    }

    public function test_a_skipped_turn_is_logged(): void
    {
        Log::spy();
        $loop = new GenerationLoop;

        $loop->withLock(1, fn () => $loop->withLock(1, fn () => null));

        Log::shouldHaveReceived('info')->once();
    }
}
