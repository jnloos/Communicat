<?php

namespace App\Services\Transfer;

use RuntimeException;
use ZipArchive;

/** Writes JSON entries into a new ZIP at $path. */
final class BundleWriter
{
    private ZipArchive $zip;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write bundle to {$path}");
        }
    }

    public function put(string $entry, array $data): void
    {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($this->zip->addFromString($entry, $content) === false) {
            throw new RuntimeException("Cannot add {$entry} to the bundle");
        }
    }

    public function close(): void
    {
        // ZipArchive reports a failed write as a warning first; the return value is the real answer.
        if (@$this->zip->close() === false) {
            throw new RuntimeException('Cannot finish the bundle');
        }
    }
}
