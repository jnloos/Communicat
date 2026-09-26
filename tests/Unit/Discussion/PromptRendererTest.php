<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\MemoryView;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_decodes_html_entities_blade_escaped(): void
    {
        $expert = new Expert(['name' => "Devil's Advocate", 'role' => 'Kritiker & Prüfer', 'description' => 'Hinterfragt alles.']);
        $expert->id = 5;

        $prompt = (new PromptRenderer)->render('prompts.partials.persona', ['expert' => $expert]);

        $this->assertStringContainsString("Devil's Advocate", $prompt);
        $this->assertStringContainsString('Kritiker & Prüfer', $prompt);
        $this->assertStringNotContainsString('&#039;', $prompt);
    }

    public function test_memory_partial_renders_all_three_layers(): void
    {
        $memory = new MemoryView(
            [['token' => 'E5', 'name' => 'Alice', 'content' => 'Ich sehe das anders.']],
            'Ich will ein Beispiel bringen.',
            'Bisher: Einigkeit über das Ziel.',
        );

        $prompt = (new PromptRenderer)->render('prompts.partials.memory', ['memory' => $memory]);

        $this->assertStringContainsString('Bisher: Einigkeit über das Ziel.', $prompt);
        $this->assertStringContainsString('Alice [E5]: Ich sehe das anders.', $prompt);
        $this->assertStringContainsString('Ich will ein Beispiel bringen.', $prompt);
    }

    public function test_memory_partial_can_hide_the_private_short_term(): void
    {
        $memory = new MemoryView([], 'geheimer Gedanke', '');

        $prompt = (new PromptRenderer)->render('prompts.partials.memory', ['memory' => $memory, 'showShortTerm' => false]);

        $this->assertStringNotContainsString('geheimer Gedanke', $prompt);
    }

    public function test_system_prompt_is_rendered_from_its_view(): void
    {
        $this->assertStringContainsString('academic discussion simulation', (new PromptRenderer)->system());
    }

    /**
     * The humans on a project own it and read along; the chat has no composer,
     * so they can never take a turn. Listing them put a fifth person in a group
     * of four and agents addressed them, which is a nuisance variable in a study
     * that measures who gets the floor.
     */
    public function test_the_roster_holds_the_experts_and_nobody_else(): void
    {
        $owner = User::factory()->create(['name' => 'Ada Owner']);
        $reader = User::factory()->create(['name' => 'Bo Reader']);

        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($owner);
        $project->addContributingUser($reader);
        $project->addContributingExpert(Expert::factory()->create(['name' => 'Cleo Expert', 'role' => 'Teacher']));

        $roster = (new PromptRenderer)->participants($project);

        $this->assertSame(['experts'], array_keys($roster));

        $rendered = (new PromptRenderer)->render('prompts.partials.participants', $roster);

        $this->assertStringContainsString('Cleo Expert', $rendered);
        $this->assertStringNotContainsString('Ada Owner', $rendered);
        $this->assertStringNotContainsString('Bo Reader', $rendered);
        $this->assertStringNotContainsString($owner->promptId, $rendered);
        $this->assertStringNotContainsString($reader->promptId, $rendered);
    }
}
