<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Memory\Memory;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    use RefreshDatabase;

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
            ['token' => "U{$user->id}", 'name' => 'Jana', 'content' => 'Frage'],
            ['token' => "E{$expert->id}", 'name' => 'Alice', 'content' => 'Antwort'],
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
