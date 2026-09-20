<?php

namespace Tests\Fakes;

use App\Llm\LlmClient;
use App\Llm\LlmFactory;

/** Hands every model key the same fake; forProject() still adds logging. */
class FakeLlmFactory extends LlmFactory
{
    public function __construct(private readonly LlmClient $client) {}

    public function adapter(string $modelKey): LlmClient
    {
        return $this->client;
    }
}
