<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Data\Directive;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reaction turns.
 *
 * The prompt has always permitted short agreement, and the round still produced
 * 58 consecutive informative turns averaging 649 characters — with the hard
 * brevity rule rendered in 52 of them and never once obeyed. The three blocks
 * that demand novelty and substance outweigh it, so on a reaction turn they are
 * removed rather than argued with.
 */
class ReactionTurnTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Eine ausreichend lange Projektbeschreibung für den Test.',
            'settings' => ['covered_points' => ['Ein behandelter Punkt']], 'user_id' => $user->id,
        ]));
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
        $this->project->addMessage('Wie seht ihr das?', $user);
    }

    private function prompt(string $role): string
    {
        $directive = new Directive(
            role: $role,
            agendaStep: 'divergenz',
            convergenceIntent: 'x',
            handBackToUser: false,
        );

        return (new PromptBuilder)->speak(
            $this->project,
            $this->expert,
            ['memory' => 'x', 'beitragsabsicht' => 'y'],
            $directive,
        );
    }

    public function test_a_normal_turn_still_carries_the_substance_blocks(): void
    {
        $prompt = $this->prompt('vertiefen');

        $this->assertStringContainsString('INHALTLICHE SUBSTANZ', $prompt);
        $this->assertStringContainsString('KEIN ECHO BEREITS GENANNTER FAKTEN', $prompt);
        $this->assertStringNotContainsString('REAKTIONS-ZUG', $prompt);
    }

    public function test_a_reaction_turn_drops_the_blocks_that_cause_long_turns(): void
    {
        $prompt = $this->prompt('kurz_reagieren');

        $this->assertStringNotContainsString('INHALTLICHE SUBSTANZ', $prompt);
        $this->assertStringNotContainsString('KEIN ECHO BEREITS GENANNTER FAKTEN', $prompt);
        $this->assertStringNotContainsString('KEIN KREISEN', $prompt);
    }

    public function test_a_reaction_turn_asks_for_conversational_moves(): void
    {
        $prompt = $this->prompt('kurz_reagieren');

        $this->assertStringContainsString('REAKTIONS-ZUG', $prompt);
        $this->assertStringContainsString('GENAU EIN bis ZWEI kurze Sätze', $prompt);
        // The pattern the user asked for by name.
        $this->assertStringContainsString('Ich stimme Sophie zu — wie siehst du das, Lena?', $prompt);
        $this->assertStringContainsString('Zustimmen ist ein vollwertiger Beitrag', $prompt);
    }

    public function test_the_reaction_turn_replaces_the_normal_length_block(): void
    {
        $this->assertStringNotContainsString('LÄNGE (Standard kurz', $this->prompt('kurz_reagieren'));
        $this->assertStringContainsString('LÄNGE (Standard kurz', $this->prompt('vertiefen'));
    }
}
