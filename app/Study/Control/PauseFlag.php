<?php

namespace App\Study\Control;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Whether the whole study is paused: a file, storage/app/study/pause.json by default.
 * A file and not the cache, because a deploy's cache:clear or optimize:clear would
 * otherwise lift a pause silently. Workers and web app share one host, so all see it.
 */
class PauseFlag
{
    public function __construct(private readonly BreakerStreak $streak) {}

    /** One stat per call, no database: the worker loop asks this every few seconds. */
    public function active(): bool
    {
        return is_file($this->path());
    }

    public function state(): ?PauseState
    {
        $path = $this->path();
        $json = @file_get_contents($path);

        if ($json === false) {
            return $this->active() ? PauseState::unreadable(CarbonImmutable::now()) : null;
        }

        return PauseState::fromJson($json, CarbonImmutable::createFromTimestamp((int) @filemtime($path)));
    }

    /**
     * True only when this call set the pause. Mode `x` creates the file atomically, so of
     * six workers tripping at once exactly one gets true, and the first reason stays.
     *
     * @throws RuntimeException when the file cannot be created: the study would run on unpaused
     */
    public function pause(string $reason, string $signal, PauseSource $source): bool
    {
        File::ensureDirectoryExists(dirname($this->path()));
        $handle = @fopen($this->path(), 'x');

        if ($handle === false && $this->active()) {
            return false;
        }

        if ($handle === false) {
            throw new RuntimeException("Could not write the pause file {$this->path()}.");
        }

        fwrite($handle, (new PauseState($reason, $signal, $source, CarbonImmutable::now()))->toJson());
        fclose($handle);

        return true;
    }

    /**
     * Also resets the streak: the old one would trip again on the first failed turn.
     *
     * @throws RuntimeException when the file stays: the study would stay paused while the caller reports it running
     */
    public function resume(): void
    {
        if (! @unlink($this->path()) && $this->active()) {
            throw new RuntimeException("Could not remove the pause file {$this->path()}.");
        }

        $this->streak->reset();
    }

    private function path(): string
    {
        return config('study.pause_file');
    }
}
