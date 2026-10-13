<?php

namespace App\Services\Transfer;

use JsonException;
use ZipArchive;

/**
 * Opens a bundle and checks it before anything reads it: a ZIP, only the
 * expected entry names (no paths that could escape), bounded sizes, a manifest
 * of this schema version. Entries are checked again when they are read.
 */
final class BundleReader
{
    private function __construct(
        private readonly ZipArchive $zip,
        private readonly array $manifest,
        private readonly int $maxEntryBytes,
    ) {}

    public static function open(
        string $path,
        int $maxUnpackedBytes = Bundle::MAX_UNPACKED_BYTES,
        int $maxEntryBytes = Bundle::MAX_ENTRY_BYTES,
    ): self {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidBundle(__('transfer.errors.not_a_zip'));
        }

        $unpacked = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (! preg_match(Bundle::ENTRY_PATTERN, $stat['name'])) {
                throw new InvalidBundle(__('transfer.errors.unexpected_entry', ['name' => $stat['name']]));
            }

            if ($stat['size'] > $maxEntryBytes) {
                throw new InvalidBundle(__('transfer.errors.too_large'));
            }

            $unpacked += $stat['size'];
        }

        if ($unpacked > $maxUnpackedBytes) {
            throw new InvalidBundle(__('transfer.errors.too_large'));
        }

        if ($zip->locateName(Bundle::MANIFEST) === false) {
            throw new InvalidBundle(__('transfer.errors.no_manifest'));
        }

        $manifest = (new self($zip, [], $maxEntryBytes))->json(Bundle::MANIFEST);

        if (($manifest['schema_version'] ?? null) !== Bundle::SCHEMA_VERSION) {
            throw new InvalidBundle(__('transfer.errors.unknown_schema'));
        }

        return new self($zip, $manifest, $maxEntryBytes);
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    /** An entry's JSON; [] only when the entry is absent. */
    public function json(string $entry): array
    {
        if ($this->zip->locateName($entry) === false) {
            return [];
        }

        $content = $this->read($entry);

        if ($content === false) {
            throw new InvalidBundle(__('transfer.errors.broken_entry', ['name' => $entry]));
        }

        if (strlen($content) > $this->maxEntryBytes) {
            throw new InvalidBundle(__('transfer.errors.too_large'));
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidBundle(__('transfer.errors.broken_entry', ['name' => $entry]));
        }

        if (! is_array($data)) {
            throw new InvalidBundle(__('transfer.errors.broken_entry', ['name' => $entry]));
        }

        return $data;
    }

    /**
     * The declared size can lie, so read at most one byte past the cap and
     * refuse what reaches it. A stream, not getFromName($entry, $length):
     * that one allocates the whole length up front, 100 MB per entry.
     */
    private function read(string $entry): string|false
    {
        $stream = $this->zip->getStream($entry);

        if ($stream === false) {
            return false;
        }

        try {
            return stream_get_contents($stream, $this->maxEntryBytes + 1);
        } finally {
            fclose($stream);
        }
    }

    /** @return list<string> */
    public function projectEntries(): array
    {
        $entries = [];

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = $this->zip->getNameIndex($index);

            if (str_starts_with($name, 'projects/')) {
                $entries[] = $name;
            }
        }

        sort($entries);

        return $entries;
    }
}
