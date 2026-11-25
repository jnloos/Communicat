<?php

namespace Tests\Feature\Study\Progress;

use App\Models\StudyEvent;
use App\Services\Study\Control\PauseFlag;
use App\Services\Study\Control\PauseSource;
use App\Services\Study\Progress\StatusText;
use App\Services\Study\Progress\StudySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Study\BuildsStudyCells;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class StudySnapshotTest extends TestCase
{
    use BuildsStudyCells;
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    public function test_without_cells_it_has_none_and_is_neither_finished_nor_stalled(): void
    {
        $snapshot = StudySnapshot::read();

        $this->assertFalse($snapshot->hasCells());
        $this->assertFalse($snapshot->finished());
        $this->assertFalse($snapshot->standstill());
        $this->assertSame(0, $snapshot->remaining());
        $this->assertNull($snapshot->pause);
    }

    public function test_it_reads_remaining_turns_and_takes_the_standstill_from_the_progress_rule(): void
    {
        $this->cell(spoken: 1);
        $this->cell(spoken: 0);

        $snapshot = StudySnapshot::read();

        $this->assertTrue($snapshot->hasCells());
        $this->assertSame(3, $snapshot->remaining());
        $this->assertSame($snapshot->progress['stale'], $snapshot->standstill());
        $this->assertFalse($snapshot->finished());
    }

    public function test_a_wave_is_finished_when_every_run_is_done(): void
    {
        $this->cell(spoken: 2);

        $this->assertTrue(StudySnapshot::read()->finished());
    }

    public function test_it_carries_pause_worker_signal_and_the_ten_latest_events(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        app(PauseFlag::class)->pause('lunch', 'manual', PauseSource::Manual);
        Cache::put('study.worker.seen', now()->subSeconds(30)->timestamp);
        foreach (range(1, 12) as $i) {
            StudyEvent::create(['kind' => 'manual', 'level' => 'info', 'message' => "event {$i}"]);
        }

        $snapshot = StudySnapshot::read();

        $this->assertSame('lunch', $snapshot->pause->reason);
        $this->assertSame(now()->subSeconds(30)->timestamp, $snapshot->workerSeenAt->timestamp);
        $this->assertCount(10, $snapshot->recentEvents);
        $this->assertSame('event 12', $snapshot->recentEvents->first()->message);
    }

    public function test_the_worker_is_silent_only_with_open_runs_and_an_old_or_missing_signal(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        $this->cell(spoken: 0);

        $this->assertTrue(StudySnapshot::read()->workerSilent());

        Cache::put('study.worker.seen', now()->subMinutes(2)->timestamp);
        $this->assertFalse(StudySnapshot::read()->workerSilent());

        Cache::put('study.worker.seen', now()->subMinutes(11)->timestamp);
        $this->assertTrue(StudySnapshot::read()->workerSilent());
    }

    public function test_the_status_text_fits_discord_and_names_pause_numbers_and_pipelines(): void
    {
        $this->travelTo('2026-11-16 14:30:00');
        $this->cell(spoken: 1);
        $this->cell(spoken: 2, pipeline: 'OrchestratorPipeline');
        app(PauseFlag::class)->pause('HTTP 401', 'auth', PauseSource::Breaker);

        $text = app(StatusText::class)->summary(StudySnapshot::read());

        $this->assertLessThanOrEqual(1800, mb_strlen($text));
        $this->assertStringContainsString('PAUSED', $text);
        $this->assertStringContainsString('1/2', $text);
        $this->assertStringContainsString('ScorePipeline: 0/1', $text);
        $this->assertStringContainsString('OrchestratorPipeline: 1/1', $text);
    }

    public function test_the_status_text_without_cells_says_so(): void
    {
        $this->assertStringContainsString('focus profile', app(StatusText::class)->summary(StudySnapshot::read()));
    }
}
