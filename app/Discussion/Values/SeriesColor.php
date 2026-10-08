<?php

namespace App\Discussion\Values;

use Stringable;

/**
 * The colour of one series in a comparison: a palette hue at the full (500)
 * or the light (300) shade. Written as "indigo-300", which is also the
 * suffix of the CSS variable (`var(--color-indigo-300)`) every display uses.
 */
final readonly class SeriesColor implements Stringable
{
    /**
     * Handed out in this order to series without a colour of their own.
     * Deliberately not the seat colours of the statistics flyout: a series is
     * not a participant. No near-identical pair (orange/amber, teal/emerald);
     * all are Flux pie colours, so a pie can take the hue as its datum colour.
     */
    public const HUES = ['indigo', 'orange', 'teal', 'pink', 'sky', 'red', 'purple', 'lime'];

    public const FULL = 500;

    public const LIGHT = 300;

    private function __construct(public string $hue, public int $shade) {}

    public static function of(string $hue, int $shade = self::FULL): self
    {
        return self::tryFrom("{$hue}-{$shade}") ?? throw new \InvalidArgumentException("[{$hue}-{$shade}] is no series colour.");
    }

    /** A stored "hue-shade", or null for anything outside the palette. */
    public static function tryFrom(mixed $value): ?self
    {
        if (! is_string($value) || preg_match('/^([a-z]+)-(\d+)$/', $value, $match) !== 1) {
            return null;
        }

        $shade = (int) $match[2];

        return in_array($match[1], self::HUES, true) && in_array($shade, [self::FULL, self::LIGHT], true)
            ? new self($match[1], $shade)
            : null;
    }

    /**
     * One colour per series: its own where it has one, otherwise the palette
     * in order, skipping hues another series already takes. Without any own
     * colour this is the plain palette order; past its end it repeats.
     *
     * @param  list<?self>  $own
     * @return list<self>
     */
    public static function assign(array $own): array
    {
        $taken = array_map(fn (self $color) => $color->hue, array_filter($own));
        $free = array_values(array_diff(self::HUES, $taken)) ?: self::HUES;
        $colors = [];
        $next = 0;

        foreach ($own as $color) {
            $colors[] = $color ?? new self($free[$next++ % count($free)], self::FULL);
        }

        return $colors;
    }

    public function __toString(): string
    {
        return "{$this->hue}-{$this->shade}";
    }
}
