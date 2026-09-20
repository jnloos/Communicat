<?php

namespace Tests\Fakes;

use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;

class FakeLlmClient implements LlmClient
{
    /** @var LlmRequest[] */
    public array $requests = [];

    /** @var array<string, string[]> */
    private array $queues = [];

    /** @var array<string, LlmException> */
    private array $failures = [];

    public function push(string $purpose, string ...$texts): static
    {
        $this->queues[$purpose] = [...($this->queues[$purpose] ?? []), ...$texts];

        return $this;
    }

    public function failOn(string $purpose, string $message = 'boom', string $kind = LlmException::KIND_API): static
    {
        $this->failures[$purpose] = new LlmException($message, $kind);

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        if (isset($this->failures[$request->purpose])) {
            throw $this->failures[$request->purpose];
        }

        $queue = $this->queues[$request->purpose] ?? ['fake answer'];
        $text = count($queue) > 1 ? array_shift($queue) : $queue[0];
        $this->queues[$request->purpose] = $queue;

        return new LlmResponse(
            text: $text,
            reasoning: '',
            model: 'fake-model',
            checkpoint: 'fake-model-001',
            tokensIn: 10,
            tokensOut: 5,
            tokensReasoning: 0,
            latencyMs: 1,
            finishReason: 'completed',
        );
    }

    public function completeMany(array $requests): array
    {
        return array_map(fn (LlmRequest $request) => $this->complete($request), $requests);
    }

    public function config(): ModelConfig
    {
        return new ModelConfig('fake', 'Fake', 'fake', 'fake-model', 1000);
    }

    /** @return LlmRequest[] */
    public function requestsFor(string $purpose): array
    {
        return array_values(array_filter($this->requests, fn (LlmRequest $r) => $r->purpose === $purpose));
    }
}
