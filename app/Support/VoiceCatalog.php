<?php

namespace App\Support;

class VoiceCatalog
{
    /**
     * Return the voice's traits for a given ID, or null if unknown. The name
     * prefix before the en-dash is stripped ("Sarah – warm, ruhig" → "warm,
     * ruhig"), so only the characteristics are shown.
     */
    public static function labelFor(?string $voiceId): ?string
    {
        if (! is_string($voiceId) || $voiceId === '') {
            return null;
        }

        foreach (['female', 'male'] as $gender) {
            foreach ((array) config("voices.$gender", []) as $voice) {
                if (($voice['id'] ?? null) === $voiceId) {
                    $label = (string) ($voice['label'] ?? $voiceId);

                    return str_contains($label, '–')
                        ? trim(\Illuminate\Support\Str::after($label, '–'))
                        : $label;
                }
            }
        }

        return null;
    }

    /**
     * Return 'female' / 'male' for a known voice ID, or null if unknown.
     */
    public static function genderFor(?string $voiceId): ?string
    {
        if (! is_string($voiceId) || $voiceId === '') {
            return null;
        }

        foreach (['female', 'male'] as $gender) {
            foreach ((array) config("voices.$gender", []) as $voice) {
                if (($voice['id'] ?? null) === $voiceId) {
                    return $gender;
                }
            }
        }

        return null;
    }
}
