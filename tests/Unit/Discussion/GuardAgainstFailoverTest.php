<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Logging\GuardAgainstFailover;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ProviderFailedOver;
use Tests\TestCase;

class GuardAgainstFailoverTest extends TestCase
{
    public function test_a_failover_is_logged_as_an_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'failover'));

        app(GuardAgainstFailover::class)->handle(new \stdClass);
    }

    /** AgentFailedOver extends ProviderFailedOver, but the dispatcher ignores parents. */
    public function test_both_sdk_failover_events_reach_the_guard(): void
    {
        $this->assertTrue(Event::hasListeners(ProviderFailedOver::class));
        $this->assertTrue(Event::hasListeners(AgentFailedOver::class));
    }
}
