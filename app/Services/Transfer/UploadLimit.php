<?php

namespace App\Services\Transfer;

/** The largest bundle the page can really accept: our cap, or less if PHP's own upload limits are lower. */
class UploadLimit
{
    public const CAP_KB = 12288;

    public static function kilobytes(): int
    {
        $limits = array_filter([
            self::iniToKilobytes((string) ini_get('upload_max_filesize')),
            self::iniToKilobytes((string) ini_get('post_max_size')),
        ], fn (int $kb): bool => $kb > 0);

        return min(self::CAP_KB, ...$limits);
    }

    public static function megabytes(): int
    {
        return max(1, intdiv(self::kilobytes(), 1024));
    }

    /** Parses ini shorthand such as 2M, 8M, 1G or 512K; 0 for empty or unlimited (-1). */
    public static function iniToKilobytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (int) $value <= 0) {
            return 0;
        }

        return (int) match (strtolower(substr($value, -1))) {
            'g' => (int) $value * 1024 * 1024,
            'm' => (int) $value * 1024,
            'k' => (int) $value,
            default => intdiv((int) $value, 1024),
        };
    }
}
