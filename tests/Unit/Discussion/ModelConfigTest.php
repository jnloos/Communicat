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

        $this->assertSame('GPT-5', $options['openai-gpt-5']);
        $this->assertArrayHasKey('anthropic-opus-5', $options);
        $this->assertArrayHasKey('gemini-3.8-flash', $options);
    }

    public function test_lists_each_provider_once_in_registry_order(): void
    {
        config()->set('ai.models', [
            'a-one' => ['label' => 'One', 'provider' => 'anthropic', 'model' => 'm1', 'max_output_tokens' => 10],
            'o-one' => ['label' => 'Two', 'provider' => 'openai', 'model' => 'm2', 'max_output_tokens' => 10],
            'a-two' => ['label' => 'Three', 'provider' => 'anthropic', 'model' => 'm3', 'max_output_tokens' => 10],
        ]);

        $this->assertSame(['anthropic', 'openai'], array_keys(ModelConfig::providers()));
    }

    public function test_provider_labels_come_from_the_translations(): void
    {
        $providers = ModelConfig::providers();

        $this->assertSame(__('projects.providers.openai'), $providers['openai']);
        $this->assertNotSame('projects.providers.openai', $providers['openai']);
        $this->assertSame(['openai', 'anthropic', 'gemini'], array_keys($providers));
    }

    public function test_lists_only_the_models_of_one_provider(): void
    {
        config()->set('ai.models', [
            'o-one' => ['label' => 'One', 'provider' => 'openai', 'model' => 'm1', 'max_output_tokens' => 10],
            'a-one' => ['label' => 'Two', 'provider' => 'anthropic', 'model' => 'm2', 'max_output_tokens' => 10],
            'o-two' => ['label' => 'Three', 'provider' => 'openai', 'model' => 'm3', 'max_output_tokens' => 10],
        ]);

        $this->assertSame(['o-one' => 'One', 'o-two' => 'Three'], ModelConfig::optionsFor('openai'));
        $this->assertSame([], ModelConfig::optionsFor('gemini'));
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
