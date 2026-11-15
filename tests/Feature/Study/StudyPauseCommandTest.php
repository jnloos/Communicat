<?php

namespace Tests\Feature\Study;

use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use Tests\TestCase;

class StudyPauseCommandTest extends TestCase
{
    use UsesTemporaryPauseFile;

    public function test_pause_with_a_reason_is_manual(): void
    {
        $this->artisan('study:pause', ['reason' => 'Rotating the key'])
            ->expectsOutputToContain('Study paused')
            ->assertSuccessful();

        $state = app(PauseFlag::class)->state();
        $this->assertSame('Rotating the key', $state->reason);
        $this->assertSame(PauseSource::Manual, $state->source);
    }

    public function test_pause_without_a_reason_says_manual(): void
    {
        $this->artisan('study:pause')->assertSuccessful();

        $this->assertSame('manual', app(PauseFlag::class)->state()->reason);
    }

    public function test_a_second_pause_names_the_first(): void
    {
        app(PauseFlag::class)->pause('Key revoked', 'auth', PauseSource::Breaker);

        $this->artisan('study:pause', ['reason' => 'again'])
            ->expectsOutputToContain('Key revoked')
            ->assertSuccessful();

        $this->assertSame('Key revoked', app(PauseFlag::class)->state()->reason);
    }

    public function test_resume_prints_the_old_state_and_lifts_the_pause(): void
    {
        app(PauseFlag::class)->pause('Key revoked', 'auth', PauseSource::Breaker);

        $this->artisan('study:resume')
            ->expectsOutputToContain('by breaker (auth): Key revoked')
            ->assertSuccessful();

        $this->assertFalse(app(PauseFlag::class)->active());
    }

    public function test_resume_without_a_pause_says_so(): void
    {
        $this->artisan('study:resume')
            ->expectsOutputToContain('not paused')
            ->assertSuccessful();
    }
}
