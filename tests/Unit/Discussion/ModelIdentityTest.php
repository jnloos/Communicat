<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\ModelIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelIdentityTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'identical' => ['gpt-5-mini', 'gpt-5-mini', true],
            'dated snapshot at OpenAI' => ['gpt-5-mini', 'gpt-5-mini-2025-08-07', true],
            'gateway prefix on the request' => ['openai/gpt-5-mini', 'gpt-5-mini', true],
            'gateway prefix and snapshot' => ['openai/gpt-5-mini', 'gpt-5-mini-2025-08-07', true],
            'prefix on the answer too' => ['openai/gpt-5-mini', 'openai/gpt-5-mini-2025-08-07', true],
            'case does not matter' => ['openai/GPT-5-mini', 'gpt-5-mini', true],
            'bigger model than asked' => ['gpt-5-mini', 'gpt-5', false],
            'asked gpt-5, gateway answers its smaller sibling' => ['gpt-5', 'gpt-5-mini', false],
            'asked openai/gpt-5, answered a mini snapshot' => ['openai/gpt-5', 'gpt-5-mini-2025-08-07', false],
            'compact snapshot date' => ['gpt-5', 'gpt-5-20250807', true],
            'nano answered for mini' => ['openai/gpt-5-mini', 'gpt-5-nano-2025-08-07', false],
            'other family' => ['openai/gpt-5-mini', 'claude-opus-5', false],
            'a longer digit run is another model' => ['gpt-5', 'gpt-55', false],
            'nothing reported is no drift' => ['gpt-5-mini', '', true],
            'blank is not reported either' => ['gpt-5-mini', '  ', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_a_served_model_matches_the_requested_one_only_by_base_name(string $requested, string $served, bool $expected): void
    {
        $this->assertSame($expected, ModelIdentity::matches($requested, $served));
    }
}
