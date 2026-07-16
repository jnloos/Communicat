<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Data\Directive;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PromptContractTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    private Expert $expert2;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Vertragstest',
            'description' => 'Beschreibung',
            'settings' => [],
            'user_id' => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
        $this->project->addMessage('Nutzerstart', $user);
    }

    public function test_speak_prompt_contains_natural_reaction_rules_and_stable_markers(): void
    {
        $builder = app(PromptBuilder::class);
        $directive = new Directive('vertiefen', 'divergenz', 'Test', false, 'r');

        $prompt = $builder->speak(
            $this->project,
            $this->expert1,
            ['memory' => '[STAND]\nx', 'beitragsabsicht' => 'Bob widersprechen.'],
            $directive,
        );

        $this->assertStringContainsString('---STEUERUNG---', $prompt);
        $this->assertStringContainsString('ADRESSAT:', $prompt);
        $this->assertStringContainsString('PAARTYP:', $prompt);
        $this->assertStringContainsString('Ich stimme Bob zu.', $prompt);
        $this->assertStringContainsString('X\'s Punkt finde ich stark', $prompt);
    }

    public function test_route_prompt_contains_user_inclusion_block_when_due(): void
    {
        config(['discussion.user_inclusion_multiplier' => 2]);

        foreach ([$this->expert1, $this->expert2, $this->expert1, $this->expert2] as $expert) {
            $this->project->addMessage('Expertenbeitrag', $expert);
        }

        $builder = app(PromptBuilder::class);
        $agents = $this->project->contributingExperts()
            ->mapWithKeys(fn (Expert $e) => [$e->id => ['name' => $e->name, 'job' => $e->job, 'prompt_id' => $e->promptId]])
            ->all();

        $prompt = $builder->moderatorRoute($this->project, $agents, 'note', [
            'agenda_phase' => 'divergenz',
            'pending_user' => null,
            'contributor_count' => 2,
            'expert_turns_since_user' => 4,
            'inclusion_threshold' => 4,
            'user_inclusion_due' => true,
        ]);

        $this->assertStringContainsString('NUTZER-EINBINDUNG FÄLLIG', $prompt);
        $this->assertStringContainsString('"address_user": false', $prompt);
    }

    public function test_think_prompt_encourages_named_follow_up_intent(): void
    {
        $prompt = app(PromptBuilder::class)->think($this->project, $this->expert1);

        $this->assertStringContainsString('GEDÄCHTNIS-UPDATE:', $prompt);
        $this->assertStringContainsString('BEITRAGSABSICHT:', $prompt);
        $this->assertStringContainsString('Frage an <Name>:', $prompt);
        $this->assertStringContainsString('Bob widersprechen', $prompt);
    }

    public function test_moderator_service_forces_address_user_when_inclusion_due(): void
    {
        $json = '{"candidates":["E'.$this->expert1->id.'"],"directive":{"role":"vertiefen","agenda_step":"divergenz","convergence_intent":"x","address_user":false},"reasoning":"Test."}';

        $client = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route('', [
            'user_inclusion_due' => true,
            'pending_user' => null,
        ]);

        $this->assertTrue($result['directive']->addressUser);
    }

    public function test_pair_type_markers_match_message_constants(): void
    {
        $allowed = [
            Message::PAIR_FRAGE_ANTWORT,
            Message::PAIR_ANSPRACHE_REAKTION,
            Message::PAIR_BEITRAG_DISKUSSION,
            Message::PAIR_SYNTHESE_DISKUSSION,
        ];

        $builder = app(PromptBuilder::class);
        $directive = new Directive('vertiefen', 'divergenz', 'Test', false, 'r');
        $prompt = $builder->speak(
            $this->project,
            $this->expert1,
            ['memory' => '[STAND]\nx', 'beitragsabsicht' => 'Test.'],
            $directive,
        );

        foreach ($allowed as $pairType) {
            $this->assertStringContainsString($pairType, $prompt);
        }
    }
}
