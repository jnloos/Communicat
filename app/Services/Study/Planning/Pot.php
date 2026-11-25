<?php

namespace App\Services\Study\Planning;

/**
 * Thesis study, see docs/study.md.
 *
 * The four name pools of the design, in their fixed order (spec 2.1): gender ×
 * ethnicity. A block pairs the focus persona's pot with the duo's.
 */
enum Pot: int
{
    case P0 = 0; // G+E+
    case P1 = 1; // G+E−
    case P2 = 2; // G−E+
    case P3 = 3; // G−E−

    /** The 16 blocks are every (focus pot, duo pot) pair: b = 4·f + d + 1. */
    public const BLOCKS = 16;

    public static function block(self $focus, self $duo): int
    {
        return 4 * $focus->value + $duo->value + 1;
    }

    public static function focusOf(int $block): self
    {
        return self::from(intdiv($block - 1, 4));
    }

    public static function duoOf(int $block): self
    {
        return self::from(($block - 1) % 4);
    }

    /** 'G+' or 'G-', the manifest's key. */
    public function gender(): string
    {
        return $this->value < 2 ? 'G+' : 'G-';
    }

    /** 'E+' or 'E-', the manifest's key. */
    public function ethnicity(): string
    {
        return $this->value % 2 === 0 ? 'E+' : 'E-';
    }

    /**
     * The profile tag, "S+G+E+" … "S−G−E−", written with the minus sign U+2212
     * like the cells that exist already and the progress page's tests.
     */
    public function profile(string $status): string
    {
        $sign = fn (bool $plus) => $plus ? '+' : "\u{2212}";

        return 'S'.$sign($status === 'h').'G'.$sign($this->gender() === 'G+').'E'.$sign($this->ethnicity() === 'E+');
    }
}
