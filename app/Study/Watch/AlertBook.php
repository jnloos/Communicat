<?php

namespace App\Study\Watch;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps an alert from repeating every minute: sends when a condition becomes true, reminds
 * after study.watch.repeat_alert_minutes while it stays true, and says all clear once when it
 * ends. The state lives in the cache (a persistent store, not `array`), not in the process.
 */
class AlertBook
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  Closure(): Alert  $alert  built only when something is sent
     * @param  bool  $once  one message per occurrence: no reminder, no all clear, re-armed when inactive
     */
    public function sync(string $kind, bool $active, Closure $alert, bool $once = false): void
    {
        $key = "study.alert.{$kind}";
        $entry = Cache::get($key);

        if (! $active) {
            if ($entry !== null) {
                Cache::forget($key);
                $once || $this->notifier->send($this->recovery($kind, $entry['since']), mail: true);
            }

            return;
        }

        if ($entry === null) {
            Cache::forever($key, ['since' => now()->timestamp, 'lastSent' => now()->timestamp]);
            $this->notifier->send($alert(), mail: true);
        } elseif (! $once && now()->timestamp - $entry['lastSent'] >= config('study.watch.repeat_alert_minutes') * 60) {
            Cache::forever($key, ['since' => $entry['since'], 'lastSent' => now()->timestamp]);
            $this->notifier->send($alert()->asReminder($this->minutesSince($entry['since'])), mail: true);
        }
    }

    private function recovery(string $kind, int $since): Alert
    {
        return new Alert($kind, AlertLevel::Ok, __('study::watch.recovered', [
            'what' => __("study::watch.kind.{$kind}"),
            'duration' => Alert::duration($this->minutesSince($since)),
        ]));
    }

    private function minutesSince(int $timestamp): int
    {
        return intdiv(now()->timestamp - $timestamp, 60);
    }
}
