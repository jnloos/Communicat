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

    /** A directory nobody may write to, for a pause file that cannot be created or removed. */
    protected function unwritableDirectory(): string
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes everywhere.');
        }

        $directory = $this->temporaryPath('-ro');
        mkdir($directory, 0500);
        $this->beforeApplicationDestroyed(function () use ($directory) {
            chmod($directory, 0700);
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        });

        return $directory;
    }
}
