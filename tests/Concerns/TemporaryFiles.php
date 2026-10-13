<?php

namespace Tests\Concerns;

/** Paths in the temp directory that are gone again after the test. */
trait TemporaryFiles
{
    protected function temporaryPath(string $suffix = '.zip'): string
    {
        $base = tempnam(sys_get_temp_dir(), 'test');
        @unlink($base);
        $path = $base.$suffix;
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
