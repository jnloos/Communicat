<?php

namespace App\Services\Tags;

/**
 * How a tag name given as text finds its category or value: case and
 * surrounding space do not count. Shared by the statistics templates, the
 * import and the study export's column names, so a name one of them finds
 * the others find too.
 */
final class TagNames
{
    public static function same(string $a, string $b): bool
    {
        return self::normal($a) === self::normal($b);
    }

    /** The form two names are compared in. */
    public static function normal(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
