<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\SeriesColor;
use PHPUnit\Framework\TestCase;

class SeriesColorTest extends TestCase
{
    public function test_a_colour_is_a_palette_hue_at_one_of_two_shades(): void
    {
        $this->assertSame('indigo-300', (string) SeriesColor::tryFrom('indigo-300'));
        $this->assertSame('teal', SeriesColor::tryFrom('teal-500')->hue);
        $this->assertNull(SeriesColor::tryFrom('indigo-200'), 'no other shade');
        $this->assertNull(SeriesColor::tryFrom('chartreuse-500'), 'no hue outside the palette');
        $this->assertNull(SeriesColor::tryFrom('indigo'));
        $this->assertNull(SeriesColor::tryFrom(null));
        $this->assertNull(SeriesColor::tryFrom(['indigo-500']));
    }

    public function test_without_explicit_colours_the_palette_runs_in_order(): void
    {
        $colours = SeriesColor::assign(array_fill(0, count(SeriesColor::HUES) + 1, null));

        $this->assertSame(
            [...array_map(fn (string $hue) => "{$hue}-500", SeriesColor::HUES), SeriesColor::HUES[0].'-500'],
            array_map('strval', $colours),
        );
    }

    public function test_series_without_a_colour_skip_the_hues_already_taken(): void
    {
        $colours = SeriesColor::assign([SeriesColor::tryFrom('indigo-500'), SeriesColor::tryFrom('indigo-300'), null, null]);

        $this->assertSame(['indigo-500', 'indigo-300', SeriesColor::HUES[1].'-500', SeriesColor::HUES[2].'-500'], array_map('strval', $colours));
    }

    public function test_the_default_palette_has_no_near_identical_pair(): void
    {
        foreach ([['orange', 'amber'], ['teal', 'emerald']] as $pair) {
            $this->assertNotSame($pair, array_values(array_intersect($pair, SeriesColor::HUES)), implode('/', $pair));
        }

        $this->assertSame(array_values(array_unique(SeriesColor::HUES)), SeriesColor::HUES);
    }
}
