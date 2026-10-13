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
        $this->zip->addFromString($entry, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
