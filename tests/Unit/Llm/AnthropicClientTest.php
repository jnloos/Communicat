<?php

namespace Tests\Unit\Llm;

use Anthropic\Client;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\ModelConfig;
use App\Llm\Providers\AnthropicClient;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    private function adapter(?float $temperature = null, ?string $effort = null): AnthropicClient
    {
        return new AnthropicClient(
            new ModelConfig('anthropic', 'Anthropic', 'anthropic', 'claude-opus-5', 4000, $temperature, $effort),
            new Client(apiKey: 'test-key'),
        );
    }

    private function message(array $content, string $stopReason = 'end_turn'): object
    {
        return (object) [
            'content' => array_map(fn (array $block) => (object) $block, $content),
            'model' => 'claude-opus-5',
            'stopReason' => $stopReason,
            'usage' => (object) ['inputTokens' => 100, 'outputTokens' => 40],
        ];
    }

    public function test_build_params_without_optional_settings(): void
    {
        $params = $this->adapter()->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame([
            'model' => 'claude-opus-5',
            'maxTokens' => 4000,
            'system' => 'SYSTEM',
            'messages' => [['role' => 'user', 'content' => 'PROMPT']],
        ], $params);
    }

    public function test_build_params_with_effort_asks_for_summarized_adaptive_thinking(): void
    {
        $params = $this->adapter(effort: 'low')->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $params['thinking']);
        $this->assertSame(['effort' => 'low'], $params['outputConfig']);
        $this->assertArrayNotHasKey('temperature', $params);
    }

    public function test_maps_text_and_thinking_blocks_separately(): void
    {
        $message = $this->message([
            ['type' => 'thinking', 'thinking' => 'kurze Überlegung'],
            ['type' => 'text', 'text' => 'Sichtbarer Beitrag.'],
        ]);

        $response = $this->adapter()->mapResponse($message, 250);

        $this->assertSame('Sichtbarer Beitrag.', $response->text);
        $this->assertSame('kurze Überlegung', $response->reasoning);
        $this->assertSame('claude-opus-5', $response->checkpoint);
        $this->assertSame(100, $response->tokensIn);
        $this->assertSame(40, $response->tokensOut);
        $this->assertSame(0, $response->tokensReasoning);
        $this->assertSame('end_turn', $response->finishReason);
    }

    public function test_refusal_is_a_failure_not_a_fallback(): void
    {
        $this->expectException(LlmException::class);

        try {
            $this->adapter()->mapResponse($this->message([], 'refusal'), 10);
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_REFUSAL, $e->kind);
            throw $e;
        }
    }

    public function test_empty_text_is_a_failure(): void
    {
        $this->expectException(LlmException::class);

        $this->adapter()->mapResponse($this->message([['type' => 'thinking', 'thinking' => 'nur gedacht']]), 10);
    }
}
