<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Support\OpenQuestionMemory;
use App\Services\PromptingPipeline\Support\UserQuestionMemory;
use App\Services\Text\MemoryFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenQuestionMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $addressee;

    private OpenQuestionMemory $questions;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
        $this->addressee = Expert::factory()->create(['name' => 'Verena Albrecht']);
        $this->project->addContributingExpert($this->addressee);

        $this->questions = new OpenQuestionMemory(new MemoryFormatter);
    }

    private function memory(): string
    {
        return (string) $this->addressee->thoughtsAbout($this->project)->refresh()->content;
    }

    /**
     * The defect: a question used to be recorded only by the asker, so the
     * person actually being asked had no idea and the question died there.
     */
    public function test_the_question_lands_in_the_addressees_memory(): void
    {
        $this->questions->record(
            $this->project,
            $this->addressee,
            'David Kaufmann',
            'Ohne Budget bleibt das Wunschdenken. Welchen Rahmen würdest du freigeben?',
        );

        $memory = $this->memory();
        $this->assertStringContainsString('[OFFENE_FRAGEN]', $memory);
        $this->assertStringContainsString('Frage von David Kaufmann:', $memory);
        $this->assertStringContainsString('Welchen Rahmen würdest du freigeben?', $memory);
    }

    public function test_only_the_closing_question_is_recorded(): void
    {
        $this->questions->record(
            $this->project,
            $this->addressee,
            'David Kaufmann',
            'Reicht das aus? Und welche Quote setzt du an?',
        );

        $memory = $this->memory();
        $this->assertStringContainsString('Und welche Quote setzt du an?', $memory);
        $this->assertStringNotContainsString('Reicht das aus?', $memory);
    }

    public function test_a_contribution_without_a_question_records_nothing(): void
    {
        $this->questions->record($this->project, $this->addressee, 'David Kaufmann', 'Das sehe ich genauso.');

        $this->assertStringNotContainsString('Frage von', $this->memory());
    }

    public function test_the_same_question_is_not_recorded_twice(): void
    {
        foreach ([1, 2] as $ignored) {
            $this->questions->record($this->project, $this->addressee, 'David Kaufmann', 'Welche Quote setzt du an?');
        }

        $this->assertSame(1, substr_count($this->memory(), 'Frage von David Kaufmann:'));
    }

    public function test_answering_clears_the_recorded_questions(): void
    {
        $this->questions->record($this->project, $this->addressee, 'David Kaufmann', 'Welche Quote setzt du an?');
        $this->questions->clear($this->project, $this->addressee);

        $this->assertStringNotContainsString('Frage von David Kaufmann:', $this->memory());
    }

    /**
     * Clearing must only drop questions put *to* this persona, not the ones the
     * persona itself is still tracking.
     */
    public function test_clearing_keeps_the_personas_own_open_questions(): void
    {
        $summary = $this->addressee->thoughtsAbout($this->project);
        $summary->content = "[E27]\nx\n[OFFENE_FRAGEN]\n- Eigene offene Frage zur Finanzierung\n[STAND]\ny";
        $summary->save();

        $this->questions->record($this->project, $this->addressee, 'David Kaufmann', 'Welche Quote setzt du an?');
        $this->questions->clear($this->project, $this->addressee);

        $memory = $this->memory();
        $this->assertStringContainsString('Eigene offene Frage zur Finanzierung', $memory);
        $this->assertStringNotContainsString('Frage von David Kaufmann:', $memory);
    }

    public function test_the_user_question_block_survives(): void
    {
        $summary = $this->addressee->thoughtsAbout($this->project);
        $summary->content = UserQuestionMemory::upsert("[E27]\nx\n[STAND]\ny", 'Wie gehen wir vor?');
        $summary->save();

        $this->questions->record($this->project, $this->addressee, 'David Kaufmann', 'Welche Quote setzt du an?');

        $memory = $this->memory();
        $this->assertStringContainsString(UserQuestionMemory::MARKER, $memory);
        $this->assertStringContainsString('Wie gehen wir vor?', $memory);
        $this->assertStringContainsString('Frage von David Kaufmann:', $memory);
    }
}
