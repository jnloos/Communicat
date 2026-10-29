<?php

namespace App\Discussion\Support;

/**
 * Whether the model a response names is the model that was asked for.
 *
 * Exact comparison would cry wolf twice: a gateway prefixes the provider
 * ("openai/gpt-5-mini" asked, "gpt-5-mini" answered) and OpenAI answers with a
 * dated snapshot ("gpt-5-mini-2025-08-07"). So the base name after the last "/"
 * must equal the answer or be followed by nothing but a date suffix. A looser
 * "starts with" would let "gpt-5-mini" pass for "gpt-5", the very downgrade a
 * gateway could make unnoticed; a bare "-" boundary is not enough for that.
 */
final class ModelIdentity
{
    /** -2025-08-07, -20250807 or -0613. */
    private const SNAPSHOT_SUFFIX = '/^-(\d{4}-\d{2}-\d{2}|\d{8}|\d{4})$/';

    /** An answer that names no model is "not reported", which is no drift. */
    public static function matches(string $requested, string $served): bool
    {
        $served = trim($served);

        if ($served === '') {
            return true;
        }

        $asked = self::baseName($requested);
        $answered = self::baseName($served);

        return $answered === $asked
            || (str_starts_with($answered, $asked) && preg_match(self::SNAPSHOT_SUFFIX, substr($answered, strlen($asked))) === 1);
    }

    private static function baseName(string $id): string
    {
        $id = trim($id);
        $slash = strrpos($id, '/');

        return strtolower($slash === false ? $id : substr($id, $slash + 1));
    }
}
