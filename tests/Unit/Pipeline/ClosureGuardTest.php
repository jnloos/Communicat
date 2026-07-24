<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The closure guard steers the directive when the periodic closure check fired.
 * It is exercised through the public route() → decorateDirective() path.
 */
class ClosureGuardTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert1);
    }

    private function route(array $context): \App\Services\PromptingPipeline\Data\Directive
    {
        // Route LLM returns a plain divergenz directive that the guard overrides.
        $json = '{"candidates":["E'.$this->expert1->id.'"],"directive":{"role":"vertiefen","agenda_step":"divergenz","convergence_intent":"x","address_user":false},"reasoning":"r"}';

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        return (new ModeratorService($this->project, $client, $prompts))->route('', $context)['directive'];
    }

    public function test_resolved_point_forces_abschluss(): void
    {
        $directive = $this->route([
            'closure_due' => true,
            'point_resolved' => true,
            'going_in_circles' => false,
            'next_move' => 'abschluss',
            'open_question' => 'Wie messen wir Erfolg?',
            'zwischenergebnis' => 'Einigkeit über die Messgröße.',
        ]);

        $this->assertSame('abschluss', $directive->agendaStep);
        $this->assertStringContainsString('Wie messen wir Erfolg?', $directive->convergenceIntent);
    }

    public function test_going_in_circles_forces_convergence(): void
    {
        $directive = $this->route([
            'closure_due' => true,
            'point_resolved' => false,
            'going_in_circles' => true,
            'next_move' => 'neuer_aspekt',
            'open_question' => 'Nächster Aspekt?',
        ]);

        $this->assertSame('konvergenz', $directive->agendaStep);
    }

    public function test_next_move_nutzer_forces_user_handoff(): void
    {
        $directive = $this->route([
            'closure_due' => true,
            'point_resolved' => false,
            'going_in_circles' => false,
            'next_move' => 'nutzer',
            'open_question' => 'Welches Budget habt ihr?',
        ]);

        $this->assertTrue($directive->addressUser);
        $this->assertStringContainsString('Budget', $directive->convergenceIntent);
    }

    public function test_pending_user_message_takes_priority_over_closure(): void
    {
        $directive = $this->route([
            'closure_due' => true,
            'point_resolved' => true,
            'next_move' => 'abschluss',
            'pending_user' => 'Was haltet ihr von Ansatz X?',
            'pending_user_name' => 'Sam',
        ]);

        // Guard bails when a user message is pending; agenda stays as routed.
        $this->assertSame('divergenz', $directive->agendaStep);
    }

    public function test_no_override_when_closure_not_due(): void
    {
        $directive = $this->route([
            'closure_due' => false,
            'point_resolved' => true,
            'next_move' => 'abschluss',
        ]);

        $this->assertSame('divergenz', $directive->agendaStep);
    }
}
