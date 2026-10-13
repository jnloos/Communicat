<?php

namespace App\Services\Transfer;

use RuntimeException;
use ZipArchive;

/**
 * Writes JSON entries into a new ZIP at $path. Each entry goes to a temporary
 * file first and the archive takes it from there on close(): addFromString()
 * would hold every entry in memory until then, the whole study at once for
 * "everything". The temporary files go with close() or with the writer.
 */
final class BundleWriter
{
    private ZipArchive $zip;

    /** @var list<string> */
    private array $entryFiles = [];

    private bool $closed = false;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write bundle to {$path}");
        }
    }

    public function put(string $entry, array $data): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bundle-entry') ?: throw new RuntimeException("Cannot stage {$entry} for the bundle");
        $this->entryFiles[] = $file;
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($file, $content) === false || $this->zip->addFile($file, $entry) === false) {
            throw new RuntimeException("Cannot add {$entry} to the bundle");
        }
    }

    public function close(): void
    {
        $this->closed = true;

        try {
            // ZipArchive reports a failed write as a warning first; the return value is the real answer.
            if (@$this->zip->close() === false) {
                throw new RuntimeException('Cannot finish the bundle');
            }
        } finally {
            $this->removeEntryFiles();
        }
    }

    /**
     * A writer given up on before close() leaves no staged entry behind. The
     * archive drops its pending entries first, or it would read the removed
     * files when it is destroyed.
     */
    public function __destruct()
    {
        if (! $this->closed) {
            $this->zip->unchangeAll();
        }

        $this->removeEntryFiles();
    }

    private function removeEntryFiles(): void
    {
        foreach ($this->entryFiles as $file) {
            @unlink($file);
        }

        $this->entryFiles = [];
    }
}
