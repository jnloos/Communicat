<?php

namespace App\Discussion\Values;

/**
 * The study's two units of speaking share: how often an agent got the floor,
 * and how much of the text it wrote. Counted in words, never model tokens.
 */
enum ShareUnit: string implements HasDefault
{
    case Turns = 'turns';
    case Words = 'words';

    public static function default(): static
    {
        return self::Turns;
    }
}
