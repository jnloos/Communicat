<?php

namespace Tests\Feature\Study\Watch;

use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class WorkerHeartbeatTest extends TestCase
{
    use UsesTemporaryPauseFile;

    public function test_a_study_worker_loop_writes_the_signal(): void
    {
        $this->travelTo('2026-11-16 10:00:00');

        event(new Looping('study', 'study'));

        $this->assertSame(now()->timestamp, Cache::get('study.worker.seen'));
    }

    public function test_a_study_worker_on_several_queues_writes_the_signal(): void
    {
        event(new Looping('study', 'study,default'));

        $this->assertNotNull(Cache::get('study.worker.seen'));
    }

    public function test_another_queue_does_not(): void
    {
        event(new Looping('database', 'default'));

        $this->assertNull(Cache::get('study.worker.seen'));
    }

    public function test_it_writes_at_most_every_fifteen_seconds(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        event(new Looping('study', 'study'));
        $first = now()->timestamp;

        $this->travel(14)->seconds();
        event(new Looping('study', 'study'));
        $this->assertSame($first, Cache::get('study.worker.seen'));

        $this->travel(1)->seconds();
        event(new Looping('study', 'study'));
        $this->assertSame($first + 15, Cache::get('study.worker.seen'));
    }

    public function test_a_paused_worker_still_signals_that_it_lives(): void
    {
        app(PauseFlag::class)->pause('test', 'manual', PauseSource::Manual);

        $result = app('events')->until(new Looping('study', 'study'));

        $this->assertFalse($result, 'the pause hook still holds the worker');
        $this->assertNotNull(Cache::get('study.worker.seen'));
    }
}
