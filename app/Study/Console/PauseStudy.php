<?php

namespace App\Study\Console;

use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use Illuminate\Console\Command;

/** Pauses the whole study: running turns finish, no worker takes the next one. */
class PauseStudy extends Command
{
    protected $signature = 'study:pause {reason? : Why, shown on resume and to the dashboard}';

    protected $description = 'Pause every study worker after its current turn.';

    public function handle(PauseFlag $pause): int
    {
        if ($pause->pause($this->argument('reason') ?? 'manual', 'manual', PauseSource::Manual)) {
            $this->info('Study paused. Running turns finish; resume with study:resume.');

            return self::SUCCESS;
        }

        $state = $pause->state();
        $this->warn("Study was already paused since {$state->since} ({$state->source?->value}, {$state->signal}): {$state->reason}");

        return self::SUCCESS;
    }
}
