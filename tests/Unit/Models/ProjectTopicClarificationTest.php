<?php

namespace Tests\Unit\Models;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTopicClarificationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Leeres Briefing',
            'description' => '',
            'settings' => [],
            'user_id' => $this->user->id,
        ]));
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    public function test_description_is_sparse_when_empty_or_short(): void
    {
        config(['discussion.topic_clarification_min_description_length' => 40]);

        $this->assertTrue($this->project->descriptionIsSparse());

        $this->project->description = 'Kurz';
        $this->assertTrue($this->project->descriptionIsSparse());

        $this->project->description = str_repeat('x', 40);
        $this->assertFalse($this->project->descriptionIsSparse());
    }

    public function test_topic_clarification_due_without_user_message_and_sparse_description(): void
    {
        config(['discussion.topic_clarification_min_description_length' => 40]);

        $this->assertTrue($this->project->topicClarificationDue());
    }

    public function test_topic_clarification_not_due_after_user_message(): void
    {
        config(['discussion.topic_clarification_min_description_length' => 40]);

        $this->project->addMessage('Bitte klären wir das Ziel.', $this->user);

        $this->assertTrue($this->project->hasUserMessage());
        $this->assertFalse($this->project->topicClarificationDue());
    }

    public function test_topic_clarification_not_due_with_rich_description(): void
    {
        config(['discussion.topic_clarification_min_description_length' => 40]);

        $this->project->description = 'Wir entwickeln eine Plattform für kommunale Bürgerbeteiligung mit Fokus auf Barrierefreiheit und Mobile First.';
        $this->project->save();

        $this->assertFalse($this->project->descriptionIsSparse());
        $this->assertFalse($this->project->topicClarificationDue());
    }
}
