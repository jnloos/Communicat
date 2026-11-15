<?php

namespace App\Study\Console;

use App\Study\Watch\DeadMansSwitch;
use App\Study\Watch\StudyWatcher;
use Illuminate\Console\Command;

/** The one-minute check (scheduler). The ping comes last and only if the checks did not throw. */
class WatchStudy extends Command
{
    protected $signature = 'study:watch';

    protected $description = 'Check the study for standstill, pause and a finished wave, and report.';

    public function handle(StudyWatcher $watcher, DeadMansSwitch $deadMansSwitch): int
    {
        $watcher->run();
        $deadMansSwitch->ping();

        return self::SUCCESS;
    }
}
