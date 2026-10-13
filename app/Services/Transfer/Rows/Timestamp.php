<?php

namespace App\Services\Transfer\Rows;

use Illuminate\Support\Carbon;

/** How a bundle writes and reads a point in time: ISO 8601, null stays null. */
final class Timestamp
{
    public static function export(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    public static function import(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
