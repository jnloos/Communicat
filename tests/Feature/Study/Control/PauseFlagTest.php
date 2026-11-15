<?php

namespace Tests\Feature\Study\Control;

use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use RuntimeException;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class PauseFlagTest extends TestCase
{
    use UsesTemporaryPauseFile;

    private PauseFlag $flag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flag = app(PauseFlag::class);
    }

    public function test_the_study_is_not_paused_without_the_file(): void
    {
        $this->assertFalse($this->flag->active());
        $this->assertNull($this->flag->state());
    }

    public function test_pause_writes_reason_signal_source_and_time(): void
    {
        $this->travelTo('2026-11-15 10:00:00');

        $this->assertTrue($this->flag->pause('Key revoked', 'auth', PauseSource::Breaker));

        $state = $this->flag->state();
        $this->assertTrue($this->flag->active());
        $this->assertSame('Key revoked', $state->reason);
        $this->assertSame('auth', $state->signal);
        $this->assertSame(PauseSource::Breaker, $state->source);
        $this->assertSame('2026-11-15 10:00:00', $state->since->format('Y-m-d H:i:s'));
    }

    public function test_a_second_pause_keeps_the_first_reason(): void
    {
        $this->flag->pause('first', 'auth', PauseSource::Breaker);

        $this->assertFalse($this->flag->pause('second', 'streak', PauseSource::Breaker));
        $this->assertSame('first', $this->flag->state()->reason);
    }

    public function test_a_pause_that_cannot_be_written_throws_and_names_the_path(): void
    {
        $path = $this->unwritableDirectory().'/pause.json';
        config(['study.pause_file' => $path]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($path);
        $this->flag->pause('manual', 'manual', PauseSource::Manual);
    }

    public function test_a_pause_that_cannot_be_removed_throws_and_names_the_path(): void
    {
        $directory = $this->unwritableDirectory();
        chmod($directory, 0700);
        config(['study.pause_file' => $directory.'/pause.json']);
        $this->flag->pause('manual', 'manual', PauseSource::Manual);
        chmod($directory, 0500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($directory.'/pause.json');
        $this->flag->resume();
    }

    public function test_resume_deletes_the_file(): void
    {
        $this->flag->pause('manual', 'manual', PauseSource::Manual);

        $this->flag->resume();

        $this->assertFalse($this->flag->active());
        $this->assertFileDoesNotExist(config('study.pause_file'));
    }

    public function test_an_unreadable_file_counts_as_paused(): void
    {
        file_put_contents(config('study.pause_file'), 'not json');

        $this->assertTrue($this->flag->active());
        $this->assertSame('unreadable', $this->flag->state()->reason);
        $this->assertNull($this->flag->state()->source);
    }

    /** A deploy runs optimize:clear; a pause in the cache would silently end there. */
    public function test_clearing_the_cache_does_not_lift_the_pause(): void
    {
        $this->flag->pause('manual', 'manual', PauseSource::Manual);

        $this->artisan('cache:clear')->assertSuccessful();

        $this->assertTrue($this->flag->active());
    }
}
