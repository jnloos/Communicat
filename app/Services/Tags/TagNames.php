<?php

namespace App\Services\Tags;

/**
 * How a tag name given as text finds its category or value: case and
 * surrounding space do not count. Shared by the comparison templates and the
 * import, so a name one of them finds the other finds too.
 */
final class TagNames
{
    public static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
