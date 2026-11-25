<?php

namespace Tests\Feature\Study\Watch;

use App\Events\BreakerTripped;
use App\Models\Project;
use App\Models\StudyEvent;
use App\Services\Study\Control\PauseFlag;
use App\Services\Study\Control\PauseSource;
use App\Services\Study\Watch\StudyWatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Study\BuildsStudyCells;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class StudyWatcherTest extends TestCase
{
    use BuildsStudyCells;
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['discord.com/*' => Http::response([], 200)]);
        config(['study.discord.token' => 't', 'study.discord.channel_id' => 'c', 'study.alerts.mail' => 'me@example.test', 'study.watch.report_every_hours' => 0]);
        $this->travelTo('2026-11-16 10:00:00');
    }

    public function test_a_standstill_is_sent_once_repeated_after_the_interval_and_cleared_once(): void
    {
        $project = $this->cell();

        $this->watch();
        $this->watch();
        $this->assertSame(1, $this->posts());

        $this->travel(config('study.watch.repeat_alert_minutes') + 1)->minutes();
        $this->watch();
        $this->assertSame(2, $this->posts());
        $this->assertStringContainsString('Still active', $this->lastPost());

        $this->spokenTurn($project, 1);
        $this->watch();
        $this->watch();
        $this->assertSame(3, $this->posts());
        $this->assertSame(['crit', 'crit', 'ok'], StudyEvent::orderBy('id')->pluck('level')->all());
    }

    public function test_the_standstill_text_names_the_worker_signal(): void
    {
        $this->cell();
        Cache::put('study.worker.seen', now()->subSeconds(12)->timestamp);

        $this->watch();

        $this->assertStringContainsString('workers are alive', $this->lastPost());
    }

    public function test_a_finished_wave_is_no_standstill_and_announces_itself_once(): void
    {
        $this->cell(spoken: 2);

        $this->watch();
        $this->watch();

        $this->assertSame(['wave_finished'], StudyEvent::pluck('kind')->all());
        $this->assertSame(1, $this->posts());
    }

    public function test_a_new_wave_after_a_finished_one_announces_again(): void
    {
        $this->cell(spoken: 2);
        $this->watch();
        $open = $this->cell();
        $this->spokenTurn($open, 1);
        $this->watch();

        $this->cell(spoken: 2);
        Project::query()->update(['turn_budget' => 2]);
        $this->spokenTurn($open, 2);
        $this->watch();

        $this->assertSame(2, StudyEvent::where('kind', 'wave_finished')->count());
    }

    public function test_a_breaker_halt_is_announced_at_once_not_twice_and_cleared_on_resume(): void
    {
        $this->cell();
        app(PauseFlag::class)->pause('HTTP 401', 'auth', PauseSource::Breaker);

        BreakerTripped::dispatch('auth', 'HTTP 401', null, CarbonImmutable::now());
        $this->watch();

        $this->assertSame(1, StudyEvent::where('kind', 'paused')->count());
        $this->assertSame(0, StudyEvent::where('kind', 'standstill')->count(), 'the pause is the cause, it has its own alert');
        $this->assertSame('crit', StudyEvent::where('kind', 'paused')->value('level'));

        app(PauseFlag::class)->resume();
        $this->watch();

        $this->assertSame(1, StudyEvent::where('kind', 'paused')->where('level', 'ok')->count());
    }

    public function test_a_manual_pause_is_only_info(): void
    {
        $this->cell(spoken: 1);
        app(PauseFlag::class)->pause('lunch', 'manual', PauseSource::Manual);

        $this->watch();

        $this->assertSame('info', StudyEvent::where('kind', 'paused')->value('level'));
    }

    public function test_a_long_pause_is_no_standstill_after_the_resume_until_the_grace_period_is_over(): void
    {
        $this->cell(spoken: 1);
        app(PauseFlag::class)->pause('lunch', 'manual', PauseSource::Manual);
        $this->watch();

        $this->travel(config('study.stale_after_minutes') + 5)->minutes();
        $this->watch();
        app(PauseFlag::class)->resume();
        $this->watch();

        $this->assertSame(0, StudyEvent::where('kind', 'standstill')->count(), 'no turn can be newer than the pause');

        $this->travel(config('study.stale_after_minutes') + 1)->minutes();
        $this->watch();

        $this->assertSame(1, StudyEvent::where('kind', 'standstill')->where('level', 'crit')->count());
    }

    public function test_pausing_during_a_standstill_sends_no_recovery(): void
    {
        $project = $this->cell();
        $this->watch();
        app(PauseFlag::class)->pause('lunch', 'manual', PauseSource::Manual);

        $this->watch();
        $this->spokenTurn($project, 1);
        $this->watch();

        $this->assertSame(['crit'], StudyEvent::where('kind', 'standstill')->pluck('level')->all());
    }

    public function test_the_report_comes_every_interval_to_discord_only(): void
    {
        config(['study.watch.report_every_hours' => 6]);
        $this->cell(spoken: 1);

        $this->watch();
        $this->assertSame(1, StudyEvent::where('kind', 'report')->count());

        $this->travel(5)->hours();
        $this->watch();
        $this->assertSame(1, StudyEvent::where('kind', 'report')->count());

        $this->travel(1)->hours();
        $this->watch();
        $this->assertSame(2, StudyEvent::where('kind', 'report')->count());
        $this->assertSame('', StudyEvent::where('kind', 'report')->value('message'));
        $this->assertCount(1, $this->sentMails(), 'the standstill of those hours, not the two reports');
    }

    public function test_no_report_for_a_finished_wave_without_pause(): void
    {
        config(['study.watch.report_every_hours' => 6]);
        $this->cell(spoken: 2);

        $this->watch();

        $this->assertSame(0, StudyEvent::where('kind', 'report')->count());
    }

    private function watch(): void
    {
        app(StudyWatcher::class)->run();
    }

    private function posts(): int
    {
        return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), '/messages'))->count();
    }

    private function lastPost(): string
    {
        /** @var Request $request */
        $request = Http::recorded()->last()[0];

        return $request['content'];
    }

    /** The `array` mailer keeps what Mail::raw sent; Mail::fake() ignores raw mails. */
    private function sentMails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->all();
    }
}
