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

        // x = 4: fold once four messages are unsummarized; y = 2: fold the two oldest.
        config(['discussion.summarize_threshold' => 4, 'discussion.summarize_oldest' => 2]);

        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung.');

        $this->project = Project::factory()->create();
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    private function summarize(): void
    {
        app(Summarize::class)->handle(new TurnPayload($this->project, 1), fn (TurnPayload $p) => $p);
    }

    /** @param  array<int, string>  $texts */
    private function addMessages(array $texts): array
    {
        return array_map(fn (string $text) => $this->project->addMessage($text, $this->expert), $texts);
    }

    public function test_does_nothing_below_the_threshold(): void
    {
        $this->addMessages(['eins', 'zwei', 'drei']);

        $this->summarize();

        SummarizeAgent::assertNeverPrompted();
        $this->assertNull($this->project->fresh()->long_term_memory);
    }

    public function test_folds_the_y_oldest_messages_once_the_threshold_is_reached(): void
    {
        $messages = $this->addMessages(['eins', 'zwei', 'drei', 'vier']);
        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung A.');

        $this->summarize();

        $project = $this->project->fresh();
        $this->assertSame('Zusammenfassung A.', $project->long_term_memory);
        // The watermark stops at the newest compressed message, not at the newest one.
        $this->assertSame($messages[1]->id, $project->summarized_until_message_id);

        SummarizeAgent::assertPromptedTimes(1);
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Alice'));
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'eins'));
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'zwei'));
        SummarizeAgent::assertNotPrompted(fn ($prompt) => str_contains($prompt->prompt, 'drei'));
    }

    public function test_a_later_run_carries_the_previous_summary_forward(): void
    {
        $this->addMessages(['eins', 'zwei', 'drei', 'vier']);
        FakeAgents::inOrder(SummarizeAgent::class, ['Zusammenfassung A.', 'Zusammenfassung B.']);
        $this->summarize();

        // 'drei' and 'vier' are still pending; two more messages reach the threshold again.
        $this->addMessages(['fünf', 'sechs']);
        $this->summarize();

        SummarizeAgent::assertPromptedTimes(2);

        // The second prompt is the only one that can carry summary A forward; asserting
        // the three conditions together is what pins them to that one prompt.
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Zusammenfassung A.')
            && str_contains($prompt->prompt, 'drei')
            && ! str_contains($prompt->prompt, 'eins'));

        $this->assertSame('Zusammenfassung B.', $this->project->fresh()->long_term_memory);
    }

    public function test_the_project_columns_override_the_configured_defaults(): void
    {
        $messages = $this->addMessages(['eins', 'zwei']);
        $this->project->update(['summarize_threshold' => 2, 'summarize_oldest' => 1]);

        $this->summarize();

        SummarizeAgent::assertPromptedTimes(1);
        SummarizeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'eins')
            && ! str_contains($prompt->prompt, 'zwei'));
        $this->assertSame($messages[0]->id, $this->project->fresh()->summarized_until_message_id);
    }

    public function test_null_columns_fall_back_to_the_configured_defaults(): void
    {
        $this->assertNull($this->project->summarize_threshold);
        $this->assertNull($this->project->summarize_oldest);

        $this->assertSame(4, $this->project->summarizeThreshold());
        $this->assertSame(2, $this->project->summarizeOldest());

        $this->project->update(['summarize_threshold' => 9, 'summarize_oldest' => 3]);

        $this->assertSame(9, $this->project->summarizeThreshold());
        $this->assertSame(3, $this->project->summarizeOldest());
    }
}
