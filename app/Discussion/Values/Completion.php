<?php

namespace App\Discussion\Values;

/**
 * One model answer. Deliberately narrow: these objects cross a process boundary
 * when prompts run in parallel, so nothing here may hold a connection or a
 * response object. reasoningTokens stays nullable — not every provider reports it,
 * and null must not be confused with zero.
 */
final readonly class Completion
{
    public function __construct(
        public string $text,
        public string $reasoning,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $reasoningTokens,
        /**
         * The decoded answer when the agent declared a schema, empty otherwise.
         * An array survives the process boundary; a response object would not.
         *
         * @var array<string, mixed>
         */
        public array $structured = [],
    ) {}

    /**
     * A text field of the structured answer, trimmed; '' when it is missing or
     * not a string. A schema is a request on some providers, not a guarantee,
     * so a field may arrive as a list or a number -- the caller then sees an
     * empty field and falls back, instead of a TypeError ending the run.
     */
    public function field(string $key): string
    {
        $value = $this->structured[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
