<?php

namespace Tests\Feature\Study;

use Tests\Concerns\TemporaryFiles;

/** Points the study's pause file at a temp path, so no test reads or writes the real one in storage/. */
trait UsesTemporaryPauseFile
{
    use TemporaryFiles;

    protected function setUpUsesTemporaryPauseFile(): void
    {
        config(['study.pause_file' => $this->temporaryPath('.json')]);
    }
}
