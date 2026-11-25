<?php

namespace App\Services\Study\Progress;

/**
 * Thesis study, see docs/study.md.
 *
 * The class an error message starts with, and nothing else: what leaves the app (Discord, mail)
 * names a kind of failure, never a message, which may hold a path or a key.
 */
final class ErrorClass
{
    public const UNKNOWN = 'unknown';

    /** The part before the first ':' when it is one class-like word, else the first word when it looks like a class. */
    public static function of(?string $error): string
    {
        $error = trim((string) $error);
        $head = strstr($error, ':', true);

        if ($head !== false && preg_match('/^[\w\\\\]+$/', $head)) {
            return $head;
        }

        $word = strtok($error, " \t\n:") ?: '';

        return preg_match('/^[\w\\\\]*(\\\\|Exception|Error)$/', $word) ? $word : self::UNKNOWN;
    }
}
