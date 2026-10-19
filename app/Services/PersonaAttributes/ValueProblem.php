<?php

namespace App\Services\PersonaAttributes;

use App\Models\PersonaAttribute;

/** Why a (trimmed) attribute value cannot be stored: a prompt line must stay one short line. */
enum ValueProblem
{
    case TooLong;
    case LineBreak;

    public static function of(string $value): ?self
    {
        return match (true) {
            mb_strlen($value) > PersonaAttribute::VALUE_MAX => self::TooLong,
            preg_match('/[\r\n]/', $value) === 1 => self::LineBreak,
            default => null,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::TooLong => 'An attribute value is at most '.PersonaAttribute::VALUE_MAX.' characters long.',
            self::LineBreak => 'An attribute value must not contain a line break.',
        };
    }
}
