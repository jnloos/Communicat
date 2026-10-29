<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Schemas\ThoughtSchema;
use App\Discussion\Values\CallStatus;
use App\Discussion\Values\ModelConfig;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/**
 * The gateway route, driven through the real SDK gateway with Http::fake: what
 * goes over the wire, what lands in prompt_logs, and that nothing else is called.
 */
class OmniRouteChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.omniroute.url' => 'http://omniroute.test/v1',
            'ai.providers.omniroute.key' => 'or-secret',
            'ai.providers.openai.key' => 'sk-test',
        ]);
    }

    public function test_the_gateway_models_are_registered_under_their_own_provider_with_direct_ids(): void
    {
        foreach (['luna' => 'Luna', 'terra' => 'Terra', 'sol' => 'Sol'] as $tier => $label) {
            $config = ModelConfig::fromConfig("omniroute-gpt-5.6-{$tier}");

            $this->assertSame('omniroute', $config->provider);
            $this->assertSame("GPT-5.6 {$label}", $config->label);
            $this->assertSame("cx/gpt-5.6-{$tier}", $config->model);
            $this->assertSame(8000, $config->maxOutputTokens);
        }
    }

    public function test_the_claude_code_route_models_have_direct_ids_and_send_no_effort(): void
    {
        foreach (['opus-5' => 'cc/claude-opus-5', 'sonnet-5' => 'cc/claude-sonnet-5', 'fable-5' => 'cc/claude-fable-5', 'haiku-4-5' => 'cc/claude-haiku-4-5-20251001'] as $key => $id) {
            $config = ModelConfig::fromConfig("omniroute-claude-{$key}");

            $this->assertSame('omniroute', $config->provider);
            $this->assertSame($id, $config->model);
            $this->assertNull($config->reasoningEffort);
        }
    }

    public function test_the_dropdown_offers_the_gateway_as_its_own_group_and_the_direct_models_stay(): void
    {
        $this->assertSame('OmniRoute', ModelConfig::providers()['omniroute']);
        $this->assertSame(
            ['omniroute-gpt-5.6-luna', 'omniroute-gpt-5.6-terra', 'omniroute-gpt-5.6-sol', 'omniroute-claude-opus-5', 'omniroute-claude-sonnet-5', 'omniroute-claude-fable-5', 'omniroute-claude-haiku-4-5'],
            array_keys(ModelConfig::optionsFor('omniroute')),
        );
        $this->assertSame(['openai-gpt-5', 'openai-gpt-5-mini', 'openai-gpt-5-nano'], array_keys(ModelConfig::optionsFor('openai')));
    }

    public function test_the_gateway_is_never_the_default(): void
    {
        $this->assertSame('openai', config('ai.default'));
        $this->assertStringStartsNotWith('omniroute', config('ai.default_model'));
    }

    public function test_a_call_goes_to_the_gateway_with_key_header_model_schema_and_effort(): void
    {
        Http::fake(['omniroute.test/*' => Http::response($this->chatAnswer())]);

        $completion = $this->agent('omniroute-gpt-5.6-luna')->ask('Denk nach.');

        $this->assertSame('Ein Gedanke.', $completion->field('thought'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'http://omniroute.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer or-secret')
                && $request->hasHeader('x-omniroute-compression', 'off')
                && $body['model'] === 'cx/gpt-5.6-luna'
                && $body['reasoning_effort'] === 'low'
                && $body['response_format']['type'] === 'json_schema'
                && isset($body['response_format']['json_schema']['schema']['properties']['thought'])
                && ! array_key_exists('reasoning', $body);
        });
    }

    /** gpt-5 refuses `max_tokens`; the cap has to leave as max_completion_tokens, and only that. */
    public function test_the_output_cap_is_sent_as_max_completion_tokens(): void
    {
        Http::fake(['omniroute.test/*' => Http::response($this->chatAnswer())]);

        $this->agent('omniroute-gpt-5.6-luna')->ask('Denk nach.');

        Http::assertSent(fn (Request $request) => $request->data()['max_completion_tokens'] === 8000
            && ! array_key_exists('max_tokens', $request->data()));
    }

    public function test_the_served_model_and_cached_tokens_land_in_prompt_logs(): void
    {
        Http::fake(['omniroute.test/*' => Http::response($this->chatAnswer(cached: 40))]);
        Log::spy();

        $this->agent('omniroute-gpt-5.6-luna')->ask('Denk nach.');

        $row = PromptLog::sole();
        $this->assertSame('omniroute', $row->provider);
        $this->assertSame('cx/gpt-5.6-luna', $row->model);
        $this->assertSame('gpt-5.6-luna', $row->model_served);
        $this->assertSame(40, $row->tokens_cached);
        $this->assertSame(3, $row->tokens_reasoning);
        $this->assertSame(CallStatus::Ok, $row->status);
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_gateway_answer_from_another_model_is_logged_as_drift_but_the_call_succeeds(): void
    {
        Http::fake(['omniroute.test/*' => Http::response($this->chatAnswer(model: 'gpt-5-nano-2025-08-07'))]);
        Log::spy();

        $this->agent('omniroute-gpt-5.6-luna')->ask('Denk nach.');

        $this->assertSame('gpt-5-nano-2025-08-07', PromptLog::sole()->model_served);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_response_without_cache_figures_or_model_stays_null_not_zero(): void
    {
        $answer = $this->chatAnswer();
        unset($answer['usage']['prompt_tokens_details'], $answer['model']);
        Http::fake(['omniroute.test/*' => Http::response($answer)]);

        $this->agent('omniroute-gpt-5.6-luna')->ask('Denk nach.');

        $row = PromptLog::sole();
        $this->assertNull($row->tokens_cached);
        $this->assertNull($row->model_served);
    }

    public function test_the_direct_openai_route_writes_the_same_columns(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5-mini-2025-08-07',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"thought":"Direkt."}']]]],
            'usage' => [
                'input_tokens' => 20, 'output_tokens' => 9,
                'input_tokens_details' => ['cached_tokens' => 12],
                'output_tokens_details' => ['reasoning_tokens' => 4],
            ],
        ])]);
        Log::spy();

        $completion = $this->agent('openai-gpt-5-mini')->ask('Denk nach.');

        $this->assertSame('Direkt.', $completion->field('thought'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request->data()['reasoning']['effort'] === 'low');

        $row = PromptLog::sole();
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-5-mini-2025-08-07', $row->model_served);
        $this->assertSame(12, $row->tokens_cached);
        Log::shouldNotHaveReceived('error');
    }

    /** One provider per call and no retry elsewhere: a failing gateway means one request and one failed row. */
    public function test_a_failing_gateway_is_called_exactly_once_and_nothing_else_is(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        try {
            $this->agent('omniroute-gpt-5.6-luna')->ask('Denk nach.');
            $this->fail('A 500 must surface as an exception.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(ExpectationFailedException::class, $e);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://omniroute.test/'));
        $row = PromptLog::sole();
        $this->assertSame(CallStatus::Failed, $row->status);
        $this->assertNull($row->model_served);
        $this->assertNull($row->tokens_cached);
    }

    public function test_prompting_the_gateway_model_with_the_wrong_provider_is_still_refused(): void
    {
        $agent = $this->agent('omniroute-gpt-5.6-luna');

        $this->expectException(\InvalidArgumentException::class);

        $agent->prompt('x', provider: Lab::OpenAI, model: 'openai/gpt-5-mini');
    }

    private function agent(string $key): ThinkAgent
    {
        return new ThinkAgent(ModelConfig::fromConfig($key), schema: ThoughtSchema::class);
    }

    private function chatAnswer(string $model = 'gpt-5.6-luna', ?int $cached = 0): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => $model,
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"thought":"Ein Gedanke."}']]],
            'usage' => [
                'prompt_tokens' => 30, 'completion_tokens' => 10,
                'prompt_tokens_details' => ['cached_tokens' => $cached],
                'completion_tokens_details' => ['reasoning_tokens' => 3],
            ],
        ];
    }
}
