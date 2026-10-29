<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\ModelConfig;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class ModelRegistryTest extends TestCase
{
    public function test_every_configured_model_names_a_provider_the_sdk_knows(): void
    {
        $models = config('ai.models');

        $this->assertNotEmpty($models);

        foreach ($models as $key => $entry) {
            $this->assertArrayHasKey($entry['provider'], config('ai.providers'),
                "Provider [{$entry['provider']}] is missing from config/ai.php.");

            // The provider is a configured name; its driver is what the SDK must know.
            $driver = config('ai.providers')[$entry['provider']]['driver'];
            $this->assertNotNull(
                $driver instanceof Lab ? $driver : Lab::tryFrom($driver),
                "Model [{$key}] names provider [{$entry['provider']}] whose driver [{$driver}] the SDK does not know.",
            );
        }
    }

    public function test_the_default_model_exists(): void
    {
        $this->assertArrayHasKey(config('ai.default_model'), config('ai.models'));
    }

    /** Every study cell runs on gpt-5-mini, so a new project starts there unless LLM_DEFAULT_MODEL says otherwise. */
    public function test_new_projects_start_on_gpt_5_mini(): void
    {
        $this->assertSame('openai-gpt-5-mini', config('ai.default_model'));
    }

    /** One entry is one model: a literal id, never an env() placeholder, and a unique key. */
    public function test_every_model_id_is_a_literal_and_every_gateway_id_is_direct(): void
    {
        foreach (config('ai.models') as $key => $entry) {
            $this->assertIsString($entry['model']);
            $this->assertStringNotContainsString('$', $entry['model'], "Model [{$key}] looks like a placeholder.");
            $this->assertStringNotContainsString('env(', $entry['model']);

            if ($entry['provider'] === 'omniroute') {
                $this->assertStringContainsString('/', $entry['model'], "[{$key}] must name one provider/model, never auto or a combo.");
                $this->assertStringNotContainsString('auto', $entry['model']);
            }
        }
    }

    /** gemini-3.8-flash style keys contain dots; the registry must be read by index. */
    public function test_a_key_with_dots_resolves_through_the_array_index(): void
    {
        $this->assertSame('gemini-3.8-flash', ModelConfig::fromConfig('gemini-3.8-flash')->model);
    }

    public function test_the_gateway_provider_is_configured_as_an_openai_compatible_driver_with_the_compression_header(): void
    {
        $gateway = config('ai.providers')['omniroute'];

        $this->assertSame('openai-compatible', $gateway['driver']);
        $this->assertSame(['x-omniroute-compression' => 'off'], $gateway['headers']);
        $this->assertNotEmpty($gateway['url']);
    }
}
