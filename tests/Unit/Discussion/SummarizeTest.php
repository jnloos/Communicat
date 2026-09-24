<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Stages\Summarize;
use App\Discussion\TurnPayload;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class SummarizeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        config(['discussion.history_keep' => 2, 'discussion.summarize_batch' => 1]);

        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung.');

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

        SummarizeAgent::assertNeverPrompted();
        $this->assertNull($this->project->fresh()->long_term_memory);
    }

    public function test_compresses_everything_but_the_newest_n_messages(): void
    {
        $messages = [];
        foreach (['eins', 'zwei', 'drei', 'vier'] as $text) {
            $messages[] = $this->project->addMessage($text, $this->expert);
        }
        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung A.');

        $this->summarize();

        $project = $this->project->fresh();
        $this->assertSame('Zusammenfassung A.', $project->long_term_memory);
        $this->assertSame($messages[1]->id, $project->summarized_until_message_id);

        SummarizeAgent::assertPromptedTimes(1);
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Alice'));
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'eins'));
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'zwei'));
        SummarizeAgent::assertNotPrompted(fn ($prompt) => str_contains($prompt->prompt, 'drei'));
    }

    public function test_a_later_run_carries_the_previous_summary_forward(): void
    {
        foreach (['eins', 'zwei', 'drei', 'vier'] as $text) {
            $this->project->addMessage($text, $this->expert);
        }
        FakeAgents::inOrder(SummarizeAgent::class, ['Zusammenfassung A.', 'Zusammenfassung B.']);
        $this->summarize();

        $this->project->addMessage('fünf', $this->expert);
        $this->project->addMessage('sechs', $this->expert);
        $this->summarize();

        SummarizeAgent::assertPromptedTimes(2);

        // The second prompt is the only one that can carry summary A forward; asserting
        // the three conditions together is what pins them to that one prompt.
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Zusammenfassung A.')
            && str_contains($prompt->prompt, 'drei')
            && ! str_contains($prompt->prompt, 'eins'));

        $this->assertSame('Zusammenfassung B.', $this->project->fresh()->long_term_memory);
    }
}
