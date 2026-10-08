<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\LocaleNumber;
use App\Discussion\Values\Metric;
use Tests\TestCase;

class LocaleNumberTest extends TestCase
{
    public function test_english_writes_a_decimal_point_and_thousands_commas(): void
    {
        app()->setLocale('en');

        $this->assertSame('22.5', LocaleNumber::decimal(22.5, 1));
        $this->assertSame('1,234', LocaleNumber::decimal(1234, 0));
        $this->assertSame('0.30', LocaleNumber::decimal(0.3, 2));
        $this->assertSame('22.5 %', LocaleNumber::percent(0.225));
    }

    public function test_german_writes_a_decimal_comma_and_thousands_points(): void
    {
        app()->setLocale('de');

        $this->assertSame('22,5', LocaleNumber::decimal(22.5, 1));
        $this->assertSame('1.234', LocaleNumber::decimal(1234, 0));
        $this->assertSame('22,5 %', LocaleNumber::percent(0.225));
    }

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
            'mean 22.5 % · range 11.7 %–35.0 % · n = 4',
            __('comparisons.results.bar_text', ['mean' => '22.5 %', 'min' => '11.7 %', 'max' => '35.0 %', 'n' => 4]),
        );
    }
}
