<?php

namespace Tests\Feature\Study\Watch;

use App\Study\Models\StudyEvent;
use App\Study\Watch\Alert;
use App\Study\Watch\AlertLevel;
use App\Study\Watch\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['study.discord.token' => 't', 'study.discord.channel_id' => 'c', 'study.alerts.mail' => 'me@example.test']);
    }

    public function test_it_writes_the_event_and_sends_to_both_channels(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 200)]);

        $this->notify();

        $this->assertSame('Stillstand', StudyEvent::sole()->message);
        Http::assertSentCount(1);
        $this->assertCount(1, $this->sentMails());
    }

    public function test_a_failing_event_write_does_not_stop_discord_or_mail(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 200)]);
        Log::spy();
        Schema::drop('study_events');

        $this->notify();

        Http::assertSentCount(1);
        $this->assertCount(1, $this->sentMails());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failing_discord_does_not_stop_mail_or_the_event(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 500)]);
        Log::spy();

        $this->notify();

        $this->assertCount(1, $this->sentMails());
        $this->assertSame(1, StudyEvent::where('kind', 'standstill')->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failing_mail_does_not_stop_discord(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 200)]);
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp down'));

        $this->notify();

        Http::assertSentCount(1);
        $this->assertSame(1, StudyEvent::count());
    }

    public function test_without_channels_only_the_event_is_written(): void
    {
        config(['study.discord.token' => null, 'study.alerts.mail' => null]);

        $this->notify();

        $this->assertSame(1, StudyEvent::count());
        Http::assertNothingSent();
        $this->assertCount(0, $this->sentMails());
    }

    public function test_a_report_goes_to_discord_without_text_in_the_event_and_never_by_mail(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 200)]);

        app(Notifier::class)->send(new Alert('report', AlertLevel::Info, 'Lagebericht', 'long body'), mail: false);

        $this->assertSame('', StudyEvent::sole()->message);
        Http::assertSentCount(1);
        $this->assertCount(0, $this->sentMails());
    }

    public function test_a_rejected_token_shows_up_as_an_event_once_per_hour(): void
    {
        Http::fake(['discord.com/*' => Http::response([], 401)]);

        $this->notify();
        $this->notify();

        $this->assertSame(1, StudyEvent::where('kind', 'discord_rejected')->count());
        $this->assertStringContainsString('401', StudyEvent::where('kind', 'discord_rejected')->value('message'));
        $this->travel(61)->minutes();
        $this->notify();
        $this->assertSame(2, StudyEvent::where('kind', 'discord_rejected')->count());
    }

    private function notify(): void
    {
        app(Notifier::class)->send(new Alert('standstill', AlertLevel::Crit, 'Stillstand', ''), mail: true);
    }

    /** The `array` mailer keeps what Mail::raw sent; Mail::fake() ignores raw mails. */
    private function sentMails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->all();
    }
}
