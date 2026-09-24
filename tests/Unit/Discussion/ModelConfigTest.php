<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\ModelConfig;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class ModelConfigTest extends TestCase
{
    public function test_builds_from_config(): void
    {
        config(['ai.models.demo' => [
            'label' => 'Demo',
            'provider' => 'openai',
            'model' => 'gpt-x',
            'max_output_tokens' => 1234,
            'temperature' => null,
            'reasoning_effort' => 'low',
        ]]);

        $config = ModelConfig::fromConfig('demo');

        $this->assertSame('demo', $config->key);
        $this->assertSame('openai', $config->provider);
        $this->assertSame('gpt-x', $config->model);
        $this->assertSame(1234, $config->maxOutputTokens);
        $this->assertNull($config->temperature);
        $this->assertSame('low', $config->reasoningEffort);
        $this->assertSame('gpt-x', $config->toArray()['model']);
    }

    public function test_lists_model_options_for_dropdowns(): void
    {
        $options = ModelConfig::options();

        $this->assertSame('OpenAI', $options['openai']);
        $this->assertArrayHasKey('anthropic', $options);
        $this->assertArrayHasKey('gemini', $options);
    }

    public function test_unknown_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModelConfig::fromConfig('does-not-exist');
    }

    public function test_the_provider_string_maps_onto_the_sdk_lab_enum(): void
    {
        config()->set('ai.models.probe', [
            'label' => 'Probe', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
            'max_output_tokens' => 8000, 'temperature' => null, 'reasoning_effort' => 'low',
        ]);

        $this->assertSame(Lab::Anthropic, ModelConfig::fromConfig('probe')->lab());
    }

    public function test_the_snapshot_keys_are_unchanged(): void
    {
        config()->set('ai.models.probe', [
            'label' => 'Probe', 'provider' => 'openai', 'model' => 'gpt-5',
            'max_output_tokens' => 8000, 'temperature' => null, 'reasoning_effort' => 'low',
        ]);

        $this->assertSame(
            ['key', 'provider', 'model', 'max_output_tokens', 'temperature', 'reasoning_effort'],
            array_keys(ModelConfig::fromConfig('probe')->toArray()),
        );
    }
}
