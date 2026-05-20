<?php

namespace App\Support;

class VoiceCatalog
{
    /**
     * Return a human-readable label for a voice ID, or null if unknown.
     */
    public static function labelFor(?string $voiceId): ?string
    {
        if (! is_string($voiceId) || $voiceId === '') {
            return null;
        }

        foreach (['female', 'male'] as $gender) {
            foreach ((array) config("voices.$gender", []) as $voice) {
                if (($voice['id'] ?? null) === $voiceId) {
                    return (string) ($voice['label'] ?? $voiceId);
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
