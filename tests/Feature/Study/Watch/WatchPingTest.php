<?php

namespace Tests\Feature\Study\Watch;

use App\Study\Watch\StudyWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class WatchPingTest extends TestCase
{
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    private const URL = 'https://hc-ping.example.test/abc';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['study.discord.token' => null, 'study.alerts.mail' => null]);
    }

    public function test_one_ping_after_a_successful_run(): void
    {
        config(['study.healthcheck_url' => self::URL]);
        Http::fake([self::URL => Http::response('OK')]);

        $this->artisan('study:watch')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::URL);
    }

    public function test_no_ping_when_the_watcher_throws(): void
    {
        config(['study.healthcheck_url' => self::URL]);
        Http::fake();
        $this->mock(StudyWatcher::class)->shouldReceive('run')->andThrow(new \RuntimeException('database gone'));

        $this->expectException(\RuntimeException::class);
        try {
            $this->artisan('study:watch')->run();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_no_url_no_call(): void
    {
        config(['study.healthcheck_url' => '']);

        $this->artisan('study:watch')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_ping_timeout_does_not_fail_the_command(): void
    {
        config(['study.healthcheck_url' => self::URL]);
        Http::fake([self::URL => fn () => throw new ConnectionException('timeout')]);

        $this->artisan('study:watch')->assertSuccessful();
    }

    public function test_a_failed_ping_logs_the_class_but_never_the_secret_url(): void
    {
        config(['study.healthcheck_url' => self::URL]);
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error for '.self::URL)]);
        Log::spy();

        $this->artisan('study:watch')->assertSuccessful();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, ConnectionException::class) && ! str_contains($message, 'hc-ping'))->once();
    }

    public function test_it_runs_every_minute_on_the_scheduler(): void
    {
        $event = collect(Schedule::events())->first(fn ($event) => str_contains($event->command, 'study:watch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
