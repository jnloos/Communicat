<?php

namespace App\Study\Watch;

use App\Study\Control\PauseFlag;
use App\Study\Events\BreakerTripped;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports a breaker pause at once instead of at the next minute. It goes through the same
 * AlertBook entry as the watcher, so whoever claims it first (an atomic add) sends and the other finds it done; if this
 * fails (it runs inside a worker's turn), the watcher catches up within a minute.
 */
class AnnounceHalt
{
    public function __construct(private readonly PauseFlag $pause, private readonly AlertBook $book, private readonly Alerts $alerts) {}

    public function handle(BreakerTripped $event): void
    {
        try {
            $state = $this->pause->state();

            if ($state !== null) {
                $this->book->sync('paused', true, fn () => $this->alerts->paused($state));
            }
        } catch (Throwable $e) {
            Log::warning('Study halt announcement failed, the watcher will catch up: '.$e->getMessage());
        }
    }
}
