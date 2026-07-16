<?php

namespace Tests\Unit\Models;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectUserInclusionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    private Expert $expert1;

    private Expert $expert2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test',
            'description' => 'Test',
            'settings' => [],
            'user_id' => $this->user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
    }

    public function test_counts_expert_turns_since_last_user_message(): void
    {
        $this->project->addMessage('Nutzerfrage', $this->user);
        $this->project->addMessage('Antwort 1', $this->expert1);
        $this->project->addMessage('Antwort 2', $this->expert2);

        $this->assertSame(2, $this->project->expertTurnsSinceLastUserMessage());
    }

    public function test_resets_count_after_new_user_message(): void
    {
        $this->project->addMessage('Erste Frage', $this->user);
        $this->project->addMessage('Antwort', $this->expert1);
        $this->project->addMessage('Zweite Frage', $this->user);

        $this->assertSame(0, $this->project->expertTurnsSinceLastUserMessage());
    }

    public function test_user_inclusion_threshold_is_multiplier_times_contributors(): void
    {
        config(['discussion.user_inclusion_multiplier' => 2]);

        $this->assertSame(4, $this->project->userInclusionThreshold());
    }

    public function test_user_inclusion_due_at_threshold(): void
    {
        config(['discussion.user_inclusion_multiplier' => 2]);

        $this->project->addMessage('Start', $this->user);

        foreach ([$this->expert1, $this->expert2, $this->expert1, $this->expert2] as $expert) {
            $this->project->addMessage('Expertenbeitrag', $expert);
        }

        $this->assertTrue($this->project->userInclusionDue());
    }

    public function test_user_inclusion_not_due_before_threshold(): void
    {
        config(['discussion.user_inclusion_multiplier' => 2]);

        $this->project->addMessage('Start', $this->user);
        $this->project->addMessage('Antwort 1', $this->expert1);
        $this->project->addMessage('Antwort 2', $this->expert2);
        $this->project->addMessage('Antwort 3', $this->expert1);

        $this->assertFalse($this->project->userInclusionDue());
    }
}
