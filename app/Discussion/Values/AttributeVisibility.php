<?php

namespace App\Discussion\Values;

/** How much of the personas' attributes a project's prompts reveal. */
enum AttributeVisibility: string implements HasDefault
{
    case None = 'none';
    case Own = 'own';
    case All = 'all';

    public static function default(): static
    {
        return self::None;
    }

    /** Whether an expert's persona block carries its own attribute sentences. */
    public function showsOwnLines(): bool
    {
        return $this !== self::None;
    }

    /** Whether the participants list carries the roster texts behind each role. */
    public function showsRoster(): bool
    {
        return $this === self::All;
    }
}
