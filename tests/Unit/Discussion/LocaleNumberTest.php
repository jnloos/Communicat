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
}
