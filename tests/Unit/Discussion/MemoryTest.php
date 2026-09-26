<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Adapting Nonomura et al. (2025): the recipient of a turn travels with the
     * message into every agent's history, so an open adjacency pair is visible
     * rather than inferred from whether a name happens to appear in the prose.
     */
    public function test_the_history_carries_who_a_turn_was_addressed_to(): void
    {
        $project = Project::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create(['name' => 'Alice'])->all();
        $bob->update(['name' => 'Bob']);
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);

        $toBob = $project->addMessage('Woher kommt die Zahl?', $alice);
        $toBob->addressee()->associate($bob);
        $toBob->save();

        $project->addMessage('Aus dem Quartalsbericht.', $bob);

        $history = app(Memory::class)->viewFor($project->fresh(), $alice)->history;

        $this->assertSame(['token' => $bob->promptId, 'name' => 'Bob'], $history[0]['addressee']);
        $this->assertNull($history[1]['addressee'], 'a turn to the group carries no addressee');
    }

    /** The rendered history shows the arrow, and omits it for a turn to the group. */
    public function test_the_rendered_history_shows_the_arrow_only_where_there_is_an_addressee(): void
    {
        $project = Project::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);

        $toBob = $project->addMessage('Woher kommt die Zahl?', $alice);
        $toBob->addressee()->associate($bob);
        $toBob->save();

        $project->addMessage('Aus dem Quartalsbericht.', $bob);

        $rendered = app(PromptRenderer::class)->render('prompts.partials.memory', [
            'memory' => app(Memory::class)->viewFor($project->fresh(), $alice),
        ]);

        $this->assertStringContainsString(
            "{$alice->name} [{$alice->promptId}] -> {$bob->name} [{$bob->promptId}]: Woher kommt die Zahl?",
            $rendered,
        );
        $this->assertStringContainsString("{$bob->name} [{$bob->promptId}]: Aus dem Quartalsbericht.", $rendered);
    }

    public function test_history_holds_participant_messages_after_the_watermark(): void
    {
        $user = User::factory()->create(['name' => 'Jana']);
        $expert = Expert::factory()->create(['name' => 'Alice']);
        $project = Project::factory()->create();
        $project->addContributingExpert($expert);

        $project->addMessage('Systemhinweis');
        $old = $project->addMessage('Alte Nachricht', $user);
        $project->addMessage('Frage', $user);
        $project->addMessage('Antwort', $expert);

        $project->update(['summarized_until_message_id' => $old->id, 'long_term_memory' => 'Bisher ging es um X.']);

        $view = (new Memory)->viewFor($project, $expert);

        $this->assertSame([
            ['token' => "U{$user->id}", 'name' => 'Jana', 'content' => 'Frage', 'addressee' => null],
            ['token' => "E{$expert->id}", 'name' => 'Alice', 'content' => 'Antwort', 'addressee' => null],
        ], $view->history);
        $this->assertSame('Bisher ging es um X.', $view->longTerm);
    }

    public function test_short_term_is_the_experts_current_thought(): void
    {
        $expert = Expert::factory()->create();
        $project = Project::factory()->create();
        Summary::create(['project_id' => $project->id, 'expert_id' => $expert->id, 'content' => 'Ich will nachhaken.']);

        $this->assertSame('Ich will nachhaken.', (new Memory)->viewFor($project, $expert)->shortTerm);
    }

    public function test_reading_memory_writes_nothing(): void
    {
        $expert = Expert::factory()->create();
        $project = Project::factory()->create();

        $view = (new Memory)->viewFor($project, $expert);

        $this->assertSame('', $view->shortTerm);
        $this->assertSame('', $view->longTerm);
        $this->assertSame(0, Summary::count());
    }

    public function test_without_an_expert_there_is_no_short_term(): void
    {
        $project = Project::factory()->create();

        $this->assertSame('', (new Memory)->viewFor($project)->shortTerm);
    }
}
