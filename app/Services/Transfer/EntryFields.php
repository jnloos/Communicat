<?php

namespace App\Services\Transfer;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Typed reads of one entry of a bundle file. A bundle is outside input: a
 * field of the wrong type throws UnreadableEntry, so the entry fails alone
 * with a note instead of taking the whole preview or import down. A missing
 * field or null reads as absent.
 */
final class EntryFields
{
    /** @return array<array-key, mixed> */
    public static function entry(mixed $entry): array
    {
        return is_array($entry) ? $entry : throw new UnreadableEntry('The entry is not an object.');
    }

    public static function text(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_scalar($value) => (string) $value,
            default => throw new UnreadableEntry("The field {$key} is not a text."),
        };
    }

    public static function integer(array $entry, string $key): ?int
    {
        $value = $entry[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 => (int) $value,
            default => throw new UnreadableEntry("The field {$key} is not a whole number."),
        };
    }

    /** A field the entry cannot do without: present and not null. */
    public static function required(array $entry, string $key): void
    {
        if (($entry[$key] ?? null) === null) {
            throw new UnreadableEntry("The field {$key} is missing.");
        }
    }

    /** Only checks: the field is absent, null or a text. */
    public static function assertText(array $entry, string $key): void
    {
        self::text($entry, $key);
    }

    /** @return list<string> */
    public static function texts(array $entry, string $key): array
    {
        $values = self::map($entry, $key);

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                throw new UnreadableEntry("The field {$key} is not a list of texts.");
            }
        }

        return array_values(array_map(fn ($value) => (string) $value, $values));
    }

    /** @return array<array-key, mixed> */
    public static function map(array $entry, string $key): array
    {
        $value = $entry[$key] ?? null;

        return match (true) {
            $value === null => [],
            is_array($value) => $value,
            default => throw new UnreadableEntry("The field {$key} is not a list."),
        };
    }

    public static function time(array $entry, string $key): ?Carbon
    {
        $value = self::text($entry, $key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new UnreadableEntry("The field {$key} is not a point in time.");
        }
    }

    /** The name an entry is reported under, whatever its shape. */
    public static function label(mixed $entry, string ...$keys): string
    {
        if (! is_array($entry)) {
            return '';
        }

        $parts = array_map(fn (string $key) => is_scalar($entry[$key] ?? null) ? trim((string) $entry[$key]) : '', $keys);

        return trim(implode(' ', $parts));
    }
}
