<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Metric;
use Tests\TestCase;

class LocaleNumberTest extends TestCase
{
    public function test_metric_format_follows_the_app_locale(): void
    {
        app()->setLocale('en');
        $this->assertSame('75.0 %', Metric::TurnShare->format(0.75));
        $this->assertSame('1,234.5', Metric::WordsPerTurn->format(1234.5));
        $this->assertSame('0.25', Metric::TurnGini->format(0.25));

        app()->setLocale('de');
        $this->assertSame('75,0 %', Metric::WordShare->format(0.75));
        $this->assertSame('0,25', Metric::WordGini->format(0.25));
    }

    public function test_the_bar_text_names_mean_and_range_in_words(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            'mean 22.5 % · range 11.7–35.0 % · n = 4',
            __('comparisons.results.bar_text', ['mean' => '22.5 %', 'range' => Metric::TurnShare->formatRange(0.117, 0.35), 'n' => 4]),
        );
    }

    public function test_a_range_writes_its_unit_once(): void
    {
        app()->setLocale('de');
        $this->assertSame('11,7–35,0 %', Metric::WordShare->formatRange(0.117, 0.35));

        app()->setLocale('en');
        $this->assertSame('12.5–80.0', Metric::WordsPerTurn->formatRange(12.5, 80));
        $this->assertSame('0.10–0.40', Metric::TurnGini->formatRange(0.1, 0.4));
    }
}
