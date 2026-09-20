<?php

namespace App\Llm;

final readonly class LlmResponse
{
    /**
     * @param  string  $text  visible answer, never contains reasoning
     * @param  string  $reasoning  reasoning summary if the provider returns one, else ''
     * @param  string  $model  the model id we asked for
     * @param  string  $checkpoint  the model id the provider reports back
     */
    public function __construct(
        public string $text,
        public string $reasoning,
        public string $model,
        public string $checkpoint,
        public int $tokensIn,
        public int $tokensOut,
        public int $tokensReasoning,
        public int $latencyMs,
        public string $finishReason,
    ) {}
}
