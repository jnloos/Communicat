<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\ModelConfig;
use App\Llm\Providers\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private function adapter(?float $temperature = null): GeminiClient
    {
        return new GeminiClient(
            new ModelConfig('gemini', 'Gemini', 'gemini', 'gemini-x', 4000, $temperature),
            'test-key',
        );
    }

    private function body(array $parts, string $finishReason = 'STOP'): array
    {
        return [
            'candidates' => [['content' => ['parts' => $parts], 'finishReason' => $finishReason]],
            'usageMetadata' => ['promptTokenCount' => 90, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 12],
            'modelVersion' => 'gemini-x-001',
        ];
    }

    public function test_build_params(): void
    {
        $params = $this->adapter(0.5)->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame('SYSTEM', $params['systemInstruction']['parts'][0]['text']);
        $this->assertSame('PROMPT', $params['contents'][0]['parts'][0]['text']);
        $this->assertSame(4000, $params['generationConfig']['maxOutputTokens']);
        $this->assertSame(0.5, $params['generationConfig']['temperature']);
        $this->assertTrue($params['generationConfig']['thinkingConfig']['includeThoughts']);
    }

    public function test_temperature_is_omitted_when_not_set(): void
    {
        $params = $this->adapter()->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertArrayNotHasKey('temperature', $params['generationConfig']);
    }

    public function test_maps_answer_and_thought_parts_separately(): void
    {
        $response = $this->adapter()->mapResponse($this->body([
            ['text' => 'innere Überlegung', 'thought' => true],
            ['text' => 'Sichtbarer Beitrag.'],
        ]), 300);

        $this->assertSame('Sichtbarer Beitrag.', $response->text);
        $this->assertSame('innere Überlegung', $response->reasoning);
        $this->assertSame('gemini-x-001', $response->checkpoint);
        $this->assertSame(90, $response->tokensIn);
        $this->assertSame(30, $response->tokensOut);
        $this->assertSame(12, $response->tokensReasoning);
        $this->assertSame('STOP', $response->finishReason);
    }

    public function test_complete_posts_to_the_model_endpoint(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->body([['text' => 'Hallo.']]))]);

        $response = $this->adapter()->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame('Hallo.', $response->text);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/models/gemini-x:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function test_http_errors_become_llm_exceptions(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $this->expectException(LlmException::class);

        $this->adapter()->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));
    }

    public function test_missing_text_is_a_failure(): void
    {
        $this->expectException(LlmException::class);

        $this->adapter()->mapResponse($this->body([], 'SAFETY'), 10);
    }
}
