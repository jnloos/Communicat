<?php

namespace Tests\Unit\Discussion;

use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class ModelRegistryTest extends TestCase
{
    public function test_every_configured_model_names_a_provider_the_sdk_knows(): void
    {
        $models = config('ai.models');

        $this->assertNotEmpty($models);

        foreach ($models as $key => $entry) {
            $this->assertNotNull(
                Lab::tryFrom($entry['provider']),
                "Model [{$key}] names provider [{$entry['provider']}], which the SDK does not know.",
            );
            $this->assertArrayHasKey($entry['provider'], config('ai.providers'),
                "Provider [{$entry['provider']}] is missing from config/ai.php.");
        }
    }

    public function test_the_default_model_exists(): void
    {
        $this->assertArrayHasKey(config('ai.default_model'), config('ai.models'));
    }
}
