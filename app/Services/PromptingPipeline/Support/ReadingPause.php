<?php

namespace App\Services\PromptingPipeline\Support;

/**
 * Computes a human reading pause from the visible length of a persona turn.
 */
class ReadingPause
{
    public static function secondsFor(string $content): int
    {
        $length = mb_strlen(trim($content));
        if ($length === 0) {
            return 0;
        }

        $charsPerSecond = max(1, (int) config('discussion.reading_chars_per_second', 18));
        $min = max(0, (int) config('discussion.reading_delay_min_seconds', 2));
        $max = max($min, (int) config('discussion.reading_delay_max_seconds', 15));

        $calculated = (int) ceil($length / $charsPerSecond);

        return max($min, min($max, $calculated));
    }
}
