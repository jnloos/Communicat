<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class FakeLlmClientTest extends TestCase
{
    public function test_returns_queued_texts_per_purpose_and_repeats_the_last(): void
    {
        $fake = (new FakeLlmClient)->push('speak', 'eins', 'zwei');

        $request = new LlmRequest('sys', 'prompt', 'speak');

        $this->assertSame('eins', $fake->complete($request)->text);
        $this->assertSame('zwei', $fake->complete($request)->text);
        $this->assertSame('zwei', $fake->complete($request)->text);
        $this->assertCount(3, $fake->requestsFor('speak'));
    }

    public function test_complete_many_preserves_keys(): void
    {
        $fake = (new FakeLlmClient)->push('think', 'a', 'b');

        $responses = $fake->completeMany([
            7 => new LlmRequest('sys', 'p1', 'think', expertId: 7),
            9 => new LlmRequest('sys', 'p2', 'think', expertId: 9),
        ]);

        $this->assertSame([7, 9], array_keys($responses));
        $this->assertSame('a', $responses[7]->text);
        $this->assertSame('b', $responses[9]->text);
    }

    public function test_can_fail_on_a_purpose(): void
    {
        $fake = (new FakeLlmClient)->failOn('speak', 'kaputt', LlmException::KIND_REFUSAL);

        try {
            $fake->complete(new LlmRequest('sys', 'prompt', 'speak'));
            $this->fail('expected an LlmException');
        } catch (LlmException $e) {
            $this->assertSame('kaputt', $e->getMessage());
            $this->assertSame(LlmException::KIND_REFUSAL, $e->kind);
        }
    }
}
