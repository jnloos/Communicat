<?php

namespace Tests\Feature\Study\Watch;

use App\Models\StudyEvent;
use App\Services\Study\Watch\Alert;
use App\Services\Study\Watch\AlertBook;
use App\Services\Study\Watch\AlertLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertBookTest extends TestCase
{
    use RefreshDatabase;

    private int $built = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['study.discord.token' => null, 'study.discord.channel_id' => null, 'study.alerts.mail' => null]);
        $this->travelTo('2026-11-16 10:00:00');
    }

    public function test_the_alert_is_only_built_when_it_is_sent(): void
    {
        $this->sync(true);
        $this->sync(true);

        $this->assertSame(1, $this->built);
    }

    public function test_a_reminder_follows_after_the_interval_and_says_how_long(): void
    {
        $this->sync(true);
        $this->travel(config('study.watch.repeat_alert_minutes') + 1)->minutes();
        $this->sync(true);

        $this->assertSame(2, StudyEvent::count());
        $this->assertStringContainsString('3 h', StudyEvent::latest('id')->value('message'));
    }

    public function test_recovery_is_sent_once_and_clears_the_entry(): void
    {
        $this->sync(true);
        $this->travel(42)->minutes();
        $this->sync(false);
        $this->sync(false);

        $this->assertSame(2, StudyEvent::count());
        $recovery = StudyEvent::latest('id')->first();
        $this->assertSame('ok', $recovery->level);
        $this->assertStringContainsString('42', $recovery->message);
        $this->assertNull(Cache::get('study.alert.thing'));
    }

    public function test_a_once_alert_neither_repeats_nor_recovers_and_rearms_when_inactive(): void
    {
        $this->sync(true, once: true);
        $this->travel(config('study.watch.repeat_alert_minutes') + 1)->minutes();
        $this->sync(true, once: true);
        $this->sync(false, once: true);

        $this->assertSame(1, StudyEvent::count());

        $this->sync(true, once: true);
        $this->assertSame(2, StudyEvent::count());
    }

    private function sync(bool $active, bool $once = false): void
    {
        app(AlertBook::class)->sync('thing', $active, function () {
            $this->built++;

            return new Alert('thing', AlertLevel::Crit, 'Thing broke', '');
        }, $once);
    }
}
