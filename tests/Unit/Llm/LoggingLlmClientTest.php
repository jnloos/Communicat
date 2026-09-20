<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LoggingLlmClient;
use App\Models\Expert;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class LoggingLlmClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_a_successful_call(): void
    {
        $expert = Expert::factory()->create();
        $client = new LoggingLlmClient((new FakeLlmClient)->push('speak', 'Hallo Welt.'));

        $response = $client->complete(new LlmRequest('SYS', 'PROMPT', 'speak', expertId: $expert->id));

        $this->assertSame('Hallo Welt.', $response->text);

        $log = PromptLog::sole();
        $this->assertSame('ok', $log->status);
        $this->assertSame('speak', $log->purpose);
        $this->assertSame("speak:{$expert->id}", $log->label);
        $this->assertSame($expert->id, $log->expert_id);
        $this->assertSame('PROMPT', $log->prompt);
        $this->assertSame('Hallo Welt.', $log->response);
        $this->assertSame('fake', $log->provider);
        $this->assertSame('fake-model-001', $log->checkpoint);
        $this->assertSame('fake-model', $log->config['model']);
        $this->assertSame(10, $log->tokens_in);
    }

    public function test_logs_a_failed_call_and_rethrows(): void
    {
        $client = new LoggingLlmClient((new FakeLlmClient)->failOn('speak', 'verweigert', LlmException::KIND_REFUSAL));

        try {
            $client->complete(new LlmRequest('SYS', 'PROMPT', 'speak'));
            $this->fail('expected an LlmException');
        } catch (LlmException) {
            // expected
        }

        $log = PromptLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('refusal: verweigert', $log->error);
        $this->assertSame('', $log->response);
    }

    public function test_logs_every_call_of_a_batch(): void
    {
        [$first, $second] = Expert::factory()->count(2)->create()->all();
        $client = new LoggingLlmClient((new FakeLlmClient)->push('think', 'a', 'b'));

        $responses = $client->completeMany([
            $first->id => new LlmRequest('SYS', 'P1', 'think', expertId: $first->id),
            $second->id => new LlmRequest('SYS', 'P2', 'think', expertId: $second->id),
        ]);

        $this->assertSame([$first->id, $second->id], array_keys($responses));
        $this->assertSame(
            ["think:{$first->id}", "think:{$second->id}"],
            PromptLog::orderBy('id')->pluck('label')->all(),
        );
    }
}
