<?php

namespace App\Llm;

interface LlmClient
{
    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Run several requests, in parallel where the implementation can.
     *
     * @param  array<array-key, LlmRequest>  $requests
     * @return array<array-key, LlmResponse> same keys as $requests
     *
     * @throws LlmException
     */
    public function completeMany(array $requests): array;

    public function config(): ModelConfig;
}
