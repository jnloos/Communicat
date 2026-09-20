<?php

namespace App\Discussion\Support;

/** The study's two length units. Never model tokens: tokenizers differ per model family. */
final class TextLength
{
    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    public static function chars(string $text): int
    {
        return mb_strlen($text);
    }
}
