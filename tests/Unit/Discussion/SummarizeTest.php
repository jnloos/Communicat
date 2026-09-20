<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\Summarize;
use App\Discussion\TurnPayload;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class SummarizeTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        config(['discussion.history_keep' => 2, 'discussion.summarize_batch' => 1]);

        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create();
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    private function summarize(): void
    {
        app(Summarize::class)->handle(new TurnPayload($this->project, 1), fn (TurnPayload $p) => $p);
    }

    public function test_does_nothing_while_the_window_is_small_enough(): void
    {
        foreach (['eins', 'zwei', 'drei'] as $text) {
            $this->project->addMessage($text, $this->expert);
        }

        $this->summarize();

        $this->assertCount(0, $this->llm->requestsFor('summarize'));
        $this->assertNull($this->project->fresh()->long_term_memory);
    }

    public function test_compresses_everything_but_the_newest_n_messages(): void
    {
        $messages = [];
        foreach (['eins', 'zwei', 'drei', 'vier'] as $text) {
            $messages[] = $this->project->addMessage($text, $this->expert);
        }
        $this->llm->push('summarize', 'Zusammenfassung A.');

        $this->summarize();

        $project = $this->project->fresh();
        $this->assertSame('Zusammenfassung A.', $project->long_term_memory);
        $this->assertSame($messages[1]->id, $project->summarized_until_message_id);

        $prompt = $this->llm->requestsFor('summarize')[0]->prompt;
        $this->assertStringContainsString('Alice', $prompt);
        $this->assertStringContainsString('eins', $prompt);
        $this->assertStringContainsString('zwei', $prompt);
        $this->assertStringNotContainsString('drei', $prompt);
    }

    public function test_a_later_run_carries_the_previous_summary_forward(): void
    {
        foreach (['eins', 'zwei', 'drei', 'vier'] as $text) {
            $this->project->addMessage($text, $this->expert);
        }
        $this->llm->push('summarize', 'Zusammenfassung A.', 'Zusammenfassung B.');
        $this->summarize();

        $this->project->addMessage('fünf', $this->expert);
        $this->project->addMessage('sechs', $this->expert);
        $this->summarize();

        $second = $this->llm->requestsFor('summarize')[1]->prompt;

        $this->assertStringContainsString('Zusammenfassung A.', $second);
        $this->assertStringContainsString('drei', $second);
        $this->assertStringNotContainsString('eins', $second);
        $this->assertSame('Zusammenfassung B.', $this->project->fresh()->long_term_memory);
    }
}
