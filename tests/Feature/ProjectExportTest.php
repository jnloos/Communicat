<?php

namespace Tests\Feature;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_export_json(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 'Test', 'description' => 'desc', 'settings' => [], 'user_id' => $owner->id,
        ]));
        $expert = Expert::factory()->create(['name' => 'Alice', 'job' => 'Architektin']);
        $project->addContributingExpert($expert);
        $project->addMessage('Hallo Alice', $owner);
        $project->addMessage('Hallo zurück', $expert);

        $this->actingAs($owner)
            ->get(route('project.export.json', $project))
            ->assertStatus(200)
            ->assertHeader('Content-Disposition', 'attachment; filename="project-' . $project->id . '-export.json"')
            ->assertJsonPath('project.title', 'Test')
            ->assertJsonPath('experts.0.name', 'Alice')
            ->assertJsonPath('messages.0.sender_type', 'user')
            ->assertJsonPath('messages.0.sender_name', $owner->name)
            ->assertJsonPath('messages.1.sender_type', 'expert')
            ->assertJsonPath('messages.1.sender_name', 'Alice');
    }

    public function test_non_member_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 'x', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));

        $this->actingAs($stranger)
            ->get(route('project.export.json', $project))
            ->assertStatus(403);
    }

    public function test_guest_redirected_to_login(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 'x', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));

        $this->get(route('project.export.json', $project))
            ->assertRedirect(route('login'));
    }
}
