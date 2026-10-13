<?php

namespace App\Services\Transfer;

/** The largest bundle the page can really accept: our cap, or less if PHP's own upload limits are lower. */
final class UploadLimit
{
    /** Mirrors Livewire's default rule for temporary uploads (max:12288); above it Livewire refuses first. */
    public const CAP_KB = 12288;

    public static function kilobytes(): int
    {
        return self::fromIni((string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size'));
    }

    /** Unlimited (0 or -1) limits drop out; with none left, the cap stands. */
    public static function fromIni(string $uploadMaxFilesize, string $postMaxSize): int
    {
        $limits = array_filter([
            self::iniToKilobytes($uploadMaxFilesize),
            self::iniToKilobytes($postMaxSize),
        ], fn (int $kb): bool => $kb > 0);

        return min([self::CAP_KB, ...$limits]);
    }

    public static function megabytes(): int
    {
        return max(1, intdiv(self::kilobytes(), 1024));
    }

    /** Parses ini shorthand such as 2M, 8M, 1G or 512K; 0 for empty or unlimited (-1). A byte value below 1 KB counts as 1 KB. */
    public static function iniToKilobytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (int) $value <= 0) {
            return 0;
        }

        return match (strtolower(substr($value, -1))) {
            'g' => (int) $value * 1024 * 1024,
            'm' => (int) $value * 1024,
            'k' => (int) $value,
            default => max(1, intdiv((int) $value, 1024)),
        };
    }
}
