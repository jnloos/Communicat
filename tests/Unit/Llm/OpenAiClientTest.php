<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\ModelConfig;
use App\Llm\Providers\OpenAiClient;
use Exception;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    private function config(?float $temperature = null, ?string $effort = null): ModelConfig
    {
        return new ModelConfig('openai', 'OpenAI', 'openai', 'gpt-x', 4000, $temperature, $effort);
    }

    public function test_build_params_omits_options_that_are_not_set(): void
    {
        $client = new OpenAiClient($this->config(), new ClientFake);

        $params = $client->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame([
            'model' => 'gpt-x',
            'instructions' => 'SYSTEM',
            'input' => 'PROMPT',
            'max_output_tokens' => 4000,
        ], $params);
    }

    public function test_build_params_sends_temperature_and_reasoning_when_set(): void
    {
        $client = new OpenAiClient($this->config(0.7, 'low'), new ClientFake);

        $params = $client->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame(0.7, $params['temperature']);
        $this->assertSame(['effort' => 'low', 'summary' => 'auto'], $params['reasoning']);
    }

    public function test_maps_the_sdk_response(): void
    {
        $sdk = CreateResponse::fake();
        $client = new OpenAiClient($this->config(), new ClientFake);

        $response = $client->mapResponse($sdk, 120);

        $this->assertSame($sdk->outputText, $response->text);
        $this->assertSame('gpt-x', $response->model);
        $this->assertSame($sdk->model, $response->checkpoint);
        $this->assertSame($sdk->usage->inputTokens, $response->tokensIn);
        $this->assertSame($sdk->usage->outputTokens, $response->tokensOut);
        $this->assertSame($sdk->usage->outputTokensDetails->reasoningTokens, $response->tokensReasoning);
        $this->assertSame(120, $response->latencyMs);
        $this->assertSame($sdk->status, $response->finishReason);
    }

    public function test_complete_calls_the_responses_api(): void
    {
        $sdk = new ClientFake([CreateResponse::fake()]);
        $client = new OpenAiClient($this->config(), $sdk);

        $response = $client->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertNotSame('', $response->text);
        $sdk->assertSent(Responses::class, fn (string $method, array $parameters) => $method === 'create'
            && $parameters['model'] === 'gpt-x'
            && $parameters['input'] === 'PROMPT');
    }

    public function test_api_errors_become_llm_exceptions(): void
    {
        $client = new OpenAiClient($this->config(), new ClientFake([new Exception('service down')]));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('service down');

        $client->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));
    }
}
