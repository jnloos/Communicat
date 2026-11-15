<?php

namespace Tests\Feature\Study\Control;

use App\Discussion\ModelDrifted;
use App\Models\JobLog;
use App\Study\Control\CircuitBreaker;
use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use App\Study\Events\BreakerTripped;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    use UsesTemporaryPauseFile;

    private CircuitBreaker $breaker;

    private PauseFlag $pause;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([BreakerTripped::class]);
        $this->breaker = app(CircuitBreaker::class);
        $this->pause = app(PauseFlag::class);
    }

    public function test_the_streak_trips_at_its_limit(): void
    {
        $this->failTurns(7);
        $this->assertFalse($this->pause->active());

        $this->failTurns(1);

        $this->assertTrue($this->pause->active());
        $this->assertSame('streak', $this->pause->state()->signal);
        $this->assertSame(PauseSource::Breaker, $this->pause->state()->source);
    }

    public function test_a_spoken_turn_resets_the_streak(): void
    {
        $this->failTurns(7);
        $this->breaker->turnSpoken();
        $this->failTurns(7);

        $this->assertFalse($this->pause->active());
    }

    public function test_an_unexpected_exception_counts_towards_the_streak(): void
    {
        foreach (range(1, 8) as $ignored) {
            $this->breaker->unexpected(1);
        }

        $this->assertSame('streak', $this->pause->state()->signal);
    }

    public function test_a_rejected_key_trips_at_once(): void
    {
        $this->breaker->turnFailed(1, new JobLog(['error' => 'PromptTaskFailed: RequestException (HTTP 401): Incorrect API key [at Gateway.php:12]']));

        $this->assertSame('auth', $this->pause->state()->signal);
        Event::assertDispatched(BreakerTripped::class, fn (BreakerTripped $event) => $event->signal === 'auth' && $event->projectId === 1);
    }

    public function test_exhausted_credit_trips_at_once(): void
    {
        $this->breaker->turnFailed(1, new JobLog(['error' => 'InsufficientCreditsException: AI provider [openai] has insufficient credits or quota.']));

        $this->assertSame('quota', $this->pause->state()->signal);
    }

    public function test_an_aborted_run_trips_at_once(): void
    {
        $this->breaker->aborted(5, new ModelDrifted('gpt-5-mini', 'gpt-5'));

        $this->assertSame('abort', $this->pause->state()->signal);
        $this->assertStringContainsString('ModelDrifted', $this->pause->state()->reason);
    }

    /** Six workers hit the same dead key at once; the alarm must go out once. */
    public function test_simultaneous_trips_report_once(): void
    {
        foreach (range(1, 6) as $project) {
            $this->breaker->aborted($project, new ModelDrifted('a', 'b'));
        }

        Event::assertDispatchedTimes(BreakerTripped::class, 1);
    }

    public function test_a_trip_during_a_manual_pause_is_not_reported(): void
    {
        $this->pause->pause('maintenance', 'manual', PauseSource::Manual);

        $this->breaker->aborted(1, new ModelDrifted('a', 'b'));

        Event::assertNotDispatched(BreakerTripped::class);
        $this->assertSame('maintenance', $this->pause->state()->reason);
    }

    private function failTurns(int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            $this->breaker->turnFailed(1, null);
        }
    }
}
