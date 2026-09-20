<?php

namespace Tests\Unit\Llm;

use App\Llm\ModelConfig;
use InvalidArgumentException;
use Tests\TestCase;

class ModelConfigTest extends TestCase
{
    public function test_builds_from_config(): void
    {
        config(['llm.models.demo' => [
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

    public function test_unknown_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModelConfig::fromConfig('does-not-exist');
    }
}
