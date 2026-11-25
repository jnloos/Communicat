<?php

namespace App\Services\Study\Watch;

use App\Models\StudyEvent;
use App\Services\Discord\DiscordClient;
use App\Services\Discord\DiscordRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The way of a message out: study_events first (so the dashboard has it whatever happens), then
 * Discord, then mail. Each channel (the event write too) fails alone: a dead channel is logged and stops neither the
 * other nor the checks that called.
 */
class Notifier
{
    public const REPORT = 'report';

    public function __construct(private readonly DiscordClient $discord) {}

    public function send(Alert $alert, bool $mail): void
    {
        $this->recordEvent($alert->kind, $alert->level, $alert->kind === self::REPORT ? '' : $alert->text());

        if ($this->discord->configured()) {
            $this->attempt('discord', fn () => $this->discord->post("**{$alert->title}**".($alert->body === '' ? '' : "\n{$alert->body}")));
        }

        if ($mail && config('study.alerts.mail')) {
            $this->attempt('mail', fn () => Mail::raw($alert->text(), fn ($message) => $message->to(config('study.alerts.mail'))->subject("[Study] {$alert->title}")));
        }
    }

    private function attempt(string $channel, callable $send): void
    {
        try {
            $send();
        } catch (DiscordRejected $e) {
            Log::warning("Study alert: discord rejected ({$e->status})");
            // Hourly, so a wrong token shows on the dashboard without filling the list every minute.
            if (Cache::add('study.alert.discord_rejected', true, 3600)) {
                $this->recordEvent('discord_rejected', AlertLevel::Warn, __('study.watch.discord_rejected', ['status' => $e->status]));
            }
        } catch (Throwable $e) {
            Log::warning("Study alert: {$channel} failed: ".$e->getMessage());
        }
    }

    private function recordEvent(string $kind, AlertLevel $level, string $message): void
    {
        $this->attempt('event', fn () => StudyEvent::create(['kind' => $kind, 'level' => $level->value, 'message' => $message]));
    }
}
