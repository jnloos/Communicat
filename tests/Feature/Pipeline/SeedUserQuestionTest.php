<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Stages\SeedUserQuestion;
use App\Services\PromptingPipeline\Support\UserQuestionMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedUserQuestionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    private Expert $expert2;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $this->user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
    }

    private function runStage(): void
    {
        $ctx = new TurnContext($this->project);
        (new SeedUserQuestion)->handle($ctx, fn ($c) => $c);
    }

    public function test_seeds_question_into_every_expert_memory_and_settings(): void
    {
        $this->project->addMessage('Wie skaliert man das System zuverlässig?', $this->user);

        $this->runStage();

        foreach ([$this->expert1, $this->expert2] as $expert) {
            $content = $expert->thoughtsAbout($this->project)->fresh()->content;
            $this->assertTrue(UserQuestionMemory::contains($content), "Expert {$expert->name} memory missing marker");
            $this->assertStringContainsString('Wie skaliert man das System', $content);
        }

        $this->project->refresh();
        $latest = $this->project->latestParticipantMessage();
        $this->assertStringContainsString('Wie skaliert man das System', $this->project->settings['current_user_question']);
        $this->assertSame($latest->id, $this->project->settings['user_question_seeded_id']);
    }

    public function test_is_idempotent_for_the_same_user_message(): void
    {
        $this->project->addMessage('Erste Frage an die Runde?', $this->user);

        $this->runStage();
        $this->project->refresh();
        $firstMemory = $this->expert1->thoughtsAbout($this->project)->fresh()->content;

        // Second run over the same latest message must not duplicate the block.
        $this->runStage();
        $secondMemory = $this->expert1->thoughtsAbout($this->project)->fresh()->content;

        $this->assertSame($firstMemory, $secondMemory);
        $this->assertSame(1, substr_count($secondMemory, UserQuestionMemory::MARKER));
    }

    public function test_does_nothing_when_latest_message_is_from_an_expert(): void
    {
        $this->project->addMessage('Ein Expertenbeitrag ohne Nutzerfrage.', $this->expert1);

        $this->runStage();

        $this->project->refresh();
        $this->assertArrayNotHasKey('current_user_question', $this->project->settings ?? []);
        $this->assertFalse(UserQuestionMemory::contains($this->expert2->thoughtsAbout($this->project)->fresh()->content ?? ''));
    }

    public function test_new_user_message_replaces_previous_seeded_question(): void
    {
        $this->project->addMessage('Frage eins?', $this->user);
        $this->runStage();

        $this->project->addMessage('Frage zwei, ganz anders?', $this->user);
        $this->runStage();

        $content = $this->expert1->thoughtsAbout($this->project)->fresh()->content;
        $this->assertStringContainsString('Frage zwei', $content);
        $this->assertStringNotContainsString('Frage eins', $content);
    }

    /**
     * A bare hand-over like "@Bob" is not a question. It used to be stored as
     * the round's current question verbatim, so every persona then anchored its
     * memory to a mention instead of to something answerable.
     */
    public function test_a_bare_mention_does_not_become_the_current_question(): void
    {
        $this->project->addMessage('Wie schätzt ihr die Lage ein?', $this->user);
        $this->runStage();

        $this->project->addMessage('@Bob', $this->user);
        $this->runStage();

        $this->assertSame(
            'Wie schätzt ihr die Lage ein?',
            $this->project->fresh()->settings['current_user_question'],
        );
        $this->assertStringContainsString(
            'Wie schätzt ihr die Lage ein?',
            (string) $this->expert1->thoughtsAbout($this->project)->fresh()->content,
        );
    }

    /**
     * A mention that also carries a question must still update it — only the
     * mention itself is stripped.
     */
    public function test_a_mention_with_substance_still_updates_the_question(): void
    {
        $this->project->addMessage('@Bob Was hältst du von Wartelisten?', $this->user);
        $this->runStage();

        $question = $this->project->fresh()->settings['current_user_question'];
        $this->assertSame('Was hältst du von Wartelisten?', $question);
    }

    public function test_every_persona_holds_the_same_question(): void
    {
        $this->project->addMessage('Welche Quote setzen wir an?', $this->user);
        $this->runStage();

        foreach ([$this->expert1, $this->expert2] as $expert) {
            $this->assertStringContainsString(
                'Welche Quote setzen wir an?',
                (string) $expert->thoughtsAbout($this->project)->fresh()->content,
            );
        }
    }
}
