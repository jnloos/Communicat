<?php

namespace App\Study\Console;

use App\Study\Control\PauseFlag;
use Illuminate\Console\Command;

/** Lifts the pause and resets the breaker's streak; the queued turns continue. */
class ResumeStudy extends Command
{
    protected $signature = 'study:resume';

    protected $description = 'Lift the study pause, whoever set it.';

    public function handle(PauseFlag $pause): int
    {
        $state = $pause->state();

        if ($state === null) {
            $this->info('Study was not paused.');

            return self::SUCCESS;
        }

        $pause->resume();
        $source = $state->source?->value ?? 'unknown';
        $this->info("Study resumed. It was paused since {$state->since->toDateTimeString()} by {$source} ({$state->signal}): {$state->reason}");

        return self::SUCCESS;
    }
}
