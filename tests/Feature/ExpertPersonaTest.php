<?php

namespace Tests\Feature;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertPersonaTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_description_is_the_plain_expert_description(): void
    {
        $expert = Expert::factory()->create(['description' => 'Pragmatische Architektin.']);
        $project = Project::factory()->create();

        $prompt = $expert->asPromptArray($project);

        $this->assertSame('Pragmatische Architektin.', $prompt['description']);
    }

    public function test_experts_table_only_has_identity_columns(): void
    {
        $this->assertSame(
            ['avatar_url', 'created_at', 'description', 'id', 'job', 'name', 'updated_at'],
            collect(Schema::getColumnListing('experts'))->sort()->values()->all()
        );
    }

    public function test_editor_saves_name_job_and_description(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ExpertEditor::class)
            ->call('edit')
            ->set('name', 'Test Persona')
            ->set('job', 'Tester')
            ->set('description', 'Prüft alles.')
            ->call('save')
            ->assertHasNoErrors();

        $expert = Expert::where('name', 'Test Persona')->firstOrFail();
        $this->assertSame('Tester', $expert->job);
        $this->assertSame('Prüft alles.', $expert->description);
    }
}
