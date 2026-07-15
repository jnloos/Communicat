<?php

namespace Tests\Feature;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PersonaListTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_beliefs_can_be_added_removed_and_saved(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $component = Livewire::test(ExpertEditor::class)
            ->call('edit')
            ->set('name', 'Test Persona')
            ->set('job', 'Tester')
            ->call('addCoreBelief')
            ->set('coreBeliefs.0', 'Erste Überzeugung')
            ->call('addCoreBelief')
            ->set('coreBeliefs.1', 'Zweite Überzeugung')
            ->call('addCoreBelief')
            ->set('coreBeliefs.2', '   ') // blank, should be filtered on save
            ->call('addKnowledgeLimit')
            ->set('knowledgeLimits.0', 'Eine Grenze')
            ->call('removeCoreBelief', 1) // remove "Zweite"
            ->call('save');

        $expert = Expert::where('name', 'Test Persona')->firstOrFail();

        $this->assertSame(['Erste Überzeugung'], $expert->core_beliefs);
        $this->assertSame(['Eine Grenze'], $expert->knowledge_limits);
    }

    public function test_as_prompt_array_renders_numbered_lists(): void
    {
        $expert = Expert::factory()->create([
            'profile' => 'Ein Profil.',
            'core_beliefs' => ['A', 'B'],
            'knowledge_limits' => ['X'],
            'style' => 'Knapp.',
        ]);
        $project = Project::factory()->create();

        $desc = $expert->asPromptArray($project)['description'];

        $this->assertStringContainsString("[Kernüberzeugungen]\n1. A\n2. B", $desc);
        $this->assertStringContainsString("[Wissensgrenzen]\n1. X", $desc);
        $this->assertStringContainsString('[Profil]', $desc);
    }
}
