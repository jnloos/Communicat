<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmClient;
use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use App\Llm\Providers\CompletesConcurrently;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class CompletesConcurrentlyTest extends TestCase
{
    public function test_runs_a_batch_through_the_factory_and_keeps_keys(): void
    {
        config(['concurrency.default' => 'sync']);

        $worker = (new FakeLlmClient)->push('think', 'eins', 'zwei');
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($worker));

        $adapter = new class implements LlmClient
        {
            use CompletesConcurrently;

            public function complete(LlmRequest $request): LlmResponse
            {
                throw new \LogicException('a batch must go through the factory');
            }

            public function config(): ModelConfig
            {
                return new ModelConfig('fake', 'Fake', 'fake', 'fake-model', 1000);
            }
        };

        $responses = $adapter->completeMany([
            11 => new LlmRequest('SYS', 'P1', 'think'),
            12 => new LlmRequest('SYS', 'P2', 'think'),
        ]);

        $this->assertSame([11, 12], array_keys($responses));
        $this->assertSame('eins', $responses[11]->text);
        $this->assertSame('zwei', $responses[12]->text);
    }
}
