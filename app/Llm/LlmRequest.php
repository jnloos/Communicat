<?php

namespace App\Llm;

final readonly class LlmRequest
{
    public const PURPOSE_THINK = 'think';

    public const PURPOSE_SELECT = 'select';

    public const PURPOSE_SPEAK = 'speak';

    public const PURPOSE_SUMMARIZE = 'summarize';

    public function __construct(
        public string $system,
        public string $prompt,
        public string $purpose,
        public ?int $jobLogId = null,
        public ?int $expertId = null,
    ) {}
}
