<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmFactory;
use App\Llm\LoggingLlmClient;
use App\Llm\Providers\AnthropicClient;
use App\Llm\Providers\GeminiClient;
use App\Llm\Providers\OpenAiClient;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LlmFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.keys' => ['openai' => 'k1', 'anthropic' => 'k2', 'gemini' => 'k3']]);
    }

    public function test_builds_the_adapter_for_each_provider(): void
    {
        $factory = new LlmFactory;

        $this->assertInstanceOf(OpenAiClient::class, $factory->adapter('openai'));
        $this->assertInstanceOf(AnthropicClient::class, $factory->adapter('anthropic'));
        $this->assertInstanceOf(GeminiClient::class, $factory->adapter('gemini'));
    }

    public function test_unknown_provider_throws(): void
    {
        config(['llm.models.odd' => ['provider' => 'nope', 'model' => 'x']]);

        $this->expectException(InvalidArgumentException::class);

        (new LlmFactory)->adapter('odd');
    }

    public function test_project_client_is_wrapped_in_the_logging_decorator(): void
    {
        $project = Project::factory()->create(['model' => 'gemini']);

        $client = (new LlmFactory)->forProject($project);

        $this->assertInstanceOf(LoggingLlmClient::class, $client);
        $this->assertSame('gemini', $client->config()->key);
    }

    public function test_lists_model_options_for_dropdowns(): void
    {
        $options = (new LlmFactory)->options();

        $this->assertSame('OpenAI', $options['openai']);
        $this->assertArrayHasKey('anthropic', $options);
        $this->assertArrayHasKey('gemini', $options);
    }
}
