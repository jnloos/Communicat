<?php

namespace App\Services\Transfer;

use JsonException;
use ZipArchive;

/**
 * Opens a bundle and checks it before anything reads it: a ZIP, a manifest of
 * this schema version, only the expected entry names (no paths that could
 * escape) and a bounded unpacked size.
 */
final class BundleReader
{
    private function __construct(private readonly ZipArchive $zip, private readonly array $manifest) {}

    public static function open(string $path): self
    {
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

            $unpacked += $stat['size'];
        }

        if ($unpacked > Bundle::MAX_UNPACKED_BYTES) {
            throw new InvalidBundle(__('transfer.errors.too_large'));
        }

        $reader = new self($zip, []);
        $manifest = $reader->json(Bundle::MANIFEST);

        if (($manifest['schema_version'] ?? null) !== Bundle::SCHEMA_VERSION) {
            throw new InvalidBundle(__('transfer.errors.unknown_schema'));
        }

        return new self($zip, $manifest);
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    /** An entry's JSON; [] when the entry is absent. */
    public function json(string $entry): array
    {
        $content = $this->zip->getFromName($entry);

        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidBundle(__('transfer.errors.broken_entry', ['name' => $entry]));
        }

        return is_array($data) ? $data : [];
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
