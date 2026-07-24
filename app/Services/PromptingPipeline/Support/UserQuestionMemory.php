<?php

namespace App\Services\PromptingPipeline\Support;

/**
 * Helpers for keeping the current user question present in an expert's plain-text
 * Gedächtnis via a `[AKTUELLE_NUTZERFRAGE]` marker block. Shared by the seeding
 * stage (writes it into every expert once per user message) and AgentService
 * (re-injects it after THINK, in case the LLM dropped it).
 */
class UserQuestionMemory
{
    public const MARKER = '[AKTUELLE_NUTZERFRAGE]';

    /**
     * Prepend (or replace) the marker block so the current question sits at the
     * top of the memory. An empty question strips the block.
     */
    public static function upsert(string $content, ?string $question): string
    {
        $stripped = self::strip($content);
        $question = trim((string) $question);

        if ($question === '') {
            return $stripped;
        }

        $block = self::MARKER."\n".$question;

        return $stripped === '' ? $block : $block."\n\n".$stripped;
    }

    /**
     * Remove an existing marker block (the marker line and its body up to the
     * next `[...]` marker line or the end of the text).
     */
    public static function strip(string $content): string
    {
        $pattern = '/^'.preg_quote(self::MARKER, '/').'.*?(?=^\[|\z)/ums';

        return trim(preg_replace($pattern, '', $content) ?? $content);
    }

    public static function contains(string $content): bool
    {
        return str_contains($content, self::MARKER);
    }
}
