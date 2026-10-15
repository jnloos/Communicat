<?php

namespace App\Discussion\Values;

/**
 * An enum with a case to fall back on when a value is missing or unknown —
 * for a choice a client sends, where an unknown value should mean "the
 * default", not an error.
 */
interface HasDefault
{
    public static function default(): static;
}
