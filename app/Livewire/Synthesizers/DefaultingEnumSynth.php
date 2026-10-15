<?php

namespace App\Livewire\Synthesizers;

use App\Discussion\Values\HasDefault;
use BackedEnum;
use Livewire\Mechanisms\HandleComponents\Synthesizers\Synth;

/**
 * Livewire's own EnumSynth hydrates an update with `from()`, so a browser that
 * sends a value outside the enum ends the request with a ValueError — a 500.
 * For a backed enum that names a default (HasDefault), this synthesizer reads
 * an unknown, empty or non-scalar value as that default instead.
 *
 * Registered in front of EnumSynth, it claims only those enums; every other
 * enum keeps Livewire's strict behaviour.
 */
class DefaultingEnumSynth extends Synth
{
    public static $key = 'denm';

    public static function match($target): bool
    {
        return $target instanceof BackedEnum && $target instanceof HasDefault;
    }

    public static function matchByType($type): bool
    {
        return is_subclass_of($type, BackedEnum::class) && is_subclass_of($type, HasDefault::class);
    }

    /** @param  class-string<BackedEnum&HasDefault>  $type */
    public static function hydrateFromType($type, $value): BackedEnum
    {
        return self::read($type, $value);
    }

    /** @return array{0: int|string, 1: array{class: class-string}} */
    public function dehydrate($target): array
    {
        return [$target->value, ['class' => $target::class]];
    }

    /** @param  array{class?: string}  $meta */
    public function hydrate($value, $meta): BackedEnum
    {
        $class = $meta['class'] ?? null;

        // The class comes from the checksummed snapshot, but check it anyway:
        // only an enum this synthesizer claims may be built here.
        abort_unless(is_string($class) && self::matchByType($class), 419);

        return self::read($class, $value);
    }

    /** @param  class-string<BackedEnum&HasDefault>  $class */
    private static function read(string $class, mixed $value): BackedEnum
    {
        $known = is_string($value) || is_int($value) ? $class::tryFrom($value) : null;

        return $known ?? $class::default();
    }
}
