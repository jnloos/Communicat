<?php

namespace App\Discussion\Support;

use Illuminate\Support\Number;

/**
 * Numbers as the reader's language writes them: the app locale decides the
 * separators ("22.5 %" in English, "22,5 %" in German). Every figure the
 * statistics show goes through here, so the flyout and the statistics page
 * never disagree.
 */
final class LocaleNumber
{
    /** Falls back to English separators if the formatter fails, never to an empty string. */
    public static function decimal(float|int $value, int $places): string
    {
        $formatted = Number::format($value, precision: $places, locale: app()->getLocale());

        return $formatted === false ? number_format($value, $places, '.', ',') : $formatted;
    }

    /** A share from 0..1 as a percentage with a space before the sign. */
    public static function percent(float $fraction, int $places = 1): string
    {
        return self::decimal($fraction * 100, $places).' %';
    }
}
