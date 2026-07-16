<?php

namespace Tests\Unit\Pipeline;

use App\Services\PromptingPipeline\Support\ReadingPause;
use Tests\TestCase;

class ReadingPauseTest extends TestCase
{
    public function test_returns_zero_for_empty_content(): void
    {
        $this->assertSame(0, ReadingPause::secondsFor(''));
        $this->assertSame(0, ReadingPause::secondsFor('   '));
    }

    public function test_applies_minimum_delay_for_short_content(): void
    {
        config([
            'discussion.reading_chars_per_second' => 18,
            'discussion.reading_delay_min_seconds' => 2,
            'discussion.reading_delay_max_seconds' => 15,
        ]);

        $this->assertSame(2, ReadingPause::secondsFor('Kurz.'));
    }

    public function test_scales_with_content_length(): void
    {
        config([
            'discussion.reading_chars_per_second' => 10,
            'discussion.reading_delay_min_seconds' => 2,
            'discussion.reading_delay_max_seconds' => 15,
        ]);

        $content = str_repeat('a', 90);

        $this->assertSame(9, ReadingPause::secondsFor($content));
    }

    public function test_clamps_to_maximum_delay(): void
    {
        config([
            'discussion.reading_chars_per_second' => 5,
            'discussion.reading_delay_min_seconds' => 2,
            'discussion.reading_delay_max_seconds' => 15,
        ]);

        $content = str_repeat('a', 500);

        $this->assertSame(15, ReadingPause::secondsFor($content));
    }
}
