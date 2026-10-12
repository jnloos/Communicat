<?php

namespace Tests\Unit\Statistics;

use App\Livewire\Statistics\GroupColor;
use PHPUnit\Framework\TestCase;

class GroupColorTest extends TestCase
{
    /** Tailwind v4 500 shades, as hex. */
    private const HEX = [
        'blue-500' => '#3b82f6', 'amber-500' => '#f59e0b', 'emerald-500' => '#10b981', 'rose-500' => '#f43f5e',
        'violet-500' => '#8b5cf6', 'cyan-500' => '#06b6d4', 'lime-500' => '#84cc16', 'fuchsia-500' => '#d946ef',
    ];

    private const TEXT = ['text-white' => '#ffffff', 'text-zinc-950' => '#09090b'];

    public function test_the_text_on_every_group_colour_reaches_wcag_aa(): void
    {
        for ($index = 0; $index < count(self::HEX); $index++) {
            $color = GroupColor::at($index);
            $text = self::TEXT[GroupColor::TEXT_ON];

            $this->assertGreaterThanOrEqual(4.5, $this->contrast($text, self::HEX[$color]), "{$color} with ".GroupColor::TEXT_ON);
        }
    }

    private function contrast(string $a, string $b): float
    {
        [$light, $dark] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
