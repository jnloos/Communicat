<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Memory\MemoryView;
use App\Discussion\Support\PromptRenderer;
use App\Models\Expert;
use Tests\TestCase;

class PromptRendererTest extends TestCase
{
    public function test_decodes_html_entities_blade_escaped(): void
    {
        $expert = new Expert(['name' => "Devil's Advocate", 'job' => 'Kritiker & Prüfer', 'description' => 'Hinterfragt alles.']);
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
        $this->assertStringContainsString('akademischen Diskussionssimulation', (new PromptRenderer)->system());
    }
}
