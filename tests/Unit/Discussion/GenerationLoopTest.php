<?php

namespace Tests\Unit\Discussion;

use App\Discussion\GenerationLoop;
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
}
