<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // The positions count participant messages only, so the system notice
        // is not one of them: "Alte Nachricht" was the first, and it has been
        // folded away -- the window therefore opens at two.
        $this->assertSame([
            ['position' => 2, 'token' => "U{$user->id}", 'name' => 'Jana', 'content' => 'Frage', 'addressee' => null],
            ['position' => 3, 'token' => "E{$expert->id}", 'name' => 'Alice', 'content' => 'Antwort', 'addressee' => null],
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

    /** A Think round asks every expert at once: the shared History is read once, each Short-Term apart. */
    public function test_views_for_several_experts_share_one_read_of_the_history(): void
    {
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();
        $project = Project::factory()->create(['long_term_memory' => 'Bisher: X.']);
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);
        $project->addMessage('Erster Beitrag.', $alice);
        Summary::create(['project_id' => $project->id, 'expert_id' => $alice->id, 'content' => 'Ich will nachhaken.']);

        DB::enableQueryLog();
        $views = (new Memory)->viewsFor($project, [$alice, $bob]);
        $historyReads = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'from "messages"') && str_contains($query['query'], 'order by'));

        $this->assertCount(1, $historyReads);
        $this->assertSame('Ich will nachhaken.', $views[$alice->id]->shortTerm);
        $this->assertSame('', $views[$bob->id]->shortTerm);
        $this->assertSame($views[$alice->id]->history, $views[$bob->id]->history);
        $this->assertSame('Bisher: X.', $views[$bob->id]->longTerm);
        $this->assertSame($views[$alice->id]->history, (new Memory)->viewFor($project, $alice)->history);
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

    /**
     * Each turn carries its position in the whole discussion, so an agent can
     * see the order rather than infer it. The count runs over the discussion,
     * not over the visible window: once older turns have been folded into
     * Long-Term, the history starts somewhere in the middle, and a window-local
     * number would tell an agent "(1)" for the forty-first contribution.
     */
    public function test_the_history_numbers_turns_by_their_place_in_the_whole_discussion(): void
    {
        $project = Project::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);

        $first = $project->addMessage('Eins.', $alice);
        $project->addMessage('Zwei.', $bob);
        $project->addMessage('Drei.', $alice);

        $history = app(Memory::class)->viewFor($project->fresh(), $alice)->history;
        $this->assertSame([1, 2, 3], array_column($history, 'position'));

        // Fold the first turn away: the window now starts at the second one,
        // and its number stays 2.
        $project->summarized_until_message_id = $first->id;
        $project->save();

        $history = app(Memory::class)->viewFor($project->fresh(), $alice)->history;
        $this->assertSame([2, 3], array_column($history, 'position'));
    }

    /** The number is rendered in front of the speaker, in brackets. */
    public function test_the_rendered_history_carries_the_position(): void
    {
        $project = Project::factory()->create();
        $alice = Expert::factory()->create(['name' => 'Alice']);
        $project->addContributingExpert($alice);

        $project->addMessage('Erster Beitrag.', $alice);
        $project->addMessage('Zweiter Beitrag.', $alice);

        $rendered = app(PromptRenderer::class)->render('prompts.partials.memory', [
            'memory' => app(Memory::class)->viewFor($project->fresh(), $alice),
        ]);

        $this->assertStringContainsString('(1) Alice', $rendered);
        $this->assertStringContainsString('(2) Alice', $rendered);
    }
}
