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

    public function test_route_prompt_contains_topic_clarification_block_when_due(): void
    {
        $builder = app(PromptBuilder::class);
        $agents = $this->project->contributingExperts()
            ->mapWithKeys(fn (Expert $e) => [$e->id => ['name' => $e->name, 'job' => $e->job, 'prompt_id' => $e->promptId]])
            ->all();

        $prompt = $builder->moderatorRoute($this->project, $agents, 'note', [
            'agenda_phase' => 'divergenz',
            'pending_user' => null,
            'topic_clarification_due' => true,
            'description_sparse' => true,
            'participant_message_count' => 0,
        ]);

        $this->assertStringContainsString('PROJEKTKONTEXT UNKLAR', $prompt);
        $this->assertStringContainsString('Klärungsfrage', $prompt);
    }

    public function test_moderator_service_forces_address_user_when_topic_clarification_due(): void
    {
        $json = '{"candidates":["E'.$this->expert1->id.'"],"directive":{"role":"vertiefen","agenda_step":"divergenz","convergence_intent":"x","address_user":false},"reasoning":"Test."}';

        $client = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route('', [
            'topic_clarification_due' => true,
            'pending_user' => null,
        ]);

        $this->assertTrue($result['directive']->addressUser);
        $this->assertStringContainsString('klären', $result['directive']->role);
    }

    public function test_speak_prompt_contains_pending_user_block_when_directive_carries_it(): void
    {
        $builder = app(PromptBuilder::class);

        $withPending = $builder->speak(
            $this->project,
            $this->expert1,
            ['memory' => '[STAND]\nx', 'beitragsabsicht' => 'Antworten.'],
            new Directive('vertiefen', 'divergenz', 'Test', false, 'r', 'Simon', 'Was kostet das?'),
        );

        $this->assertStringContainsString('OFFENE NUTZERNACHRICHT', $withPending);
        $this->assertStringContainsString('Simon', $withPending);
        $this->assertStringContainsString('Was kostet das?', $withPending);

        $withoutPending = $builder->speak(
            $this->project,
            $this->expert1,
            ['memory' => '[STAND]\nx', 'beitragsabsicht' => 'Antworten.'],
            new Directive('vertiefen', 'divergenz', 'Test', false, 'r'),
        );

        $this->assertStringNotContainsString('OFFENE NUTZERNACHRICHT', $withoutPending);
    }

    public function test_speak_prompt_carries_brevity_signal_after_long_expert_streak(): void
    {
        config(['discussion.brevity_streak' => 3, 'discussion.brevity_min_chars' => 200]);

        $builder = app(PromptBuilder::class);
        $directive = new Directive('vertiefen', 'divergenz', 'Test', false, 'r');
        $think = ['memory' => '[STAND]\nx', 'beitragsabsicht' => 'Test.'];

        $this->assertStringNotContainsString(
            'KÜRZE-SIGNAL',
            $builder->speak($this->project, $this->expert1, $think, $directive),
        );

        $long = str_repeat('Sehr langer Beitrag mit Substanz. ', 10);
        foreach ([$this->expert1, $this->expert2, $this->expert1] as $expert) {
            $this->project->addMessage($long, $expert);
        }

        $this->assertStringContainsString(
            'KÜRZE-SIGNAL',
            $builder->speak($this->project, $this->expert1, $think, $directive),
        );
    }

    public function test_speak_prompt_forbids_name_opening_after_recent_name_openers(): void
    {
        $builder = app(PromptBuilder::class);
        $directive = new Directive('vertiefen', 'divergenz', 'Test', false, 'r');
        $think = ['memory' => '[STAND]\nx', 'beitragsabsicht' => 'Test.'];

        $this->assertStringNotContainsString(
            'HARTE ZUSATZREGEL',
            $builder->speak($this->project, $this->expert1, $think, $directive),
        );

        $this->project->addMessage('Bob, das sehe ich anders.', $this->expert1);

        $this->assertStringContainsString(
            'HARTE ZUSATZREGEL',
            $builder->speak($this->project, $this->expert2, $think, $directive),
        );
    }

    public function test_think_prompt_offers_short_agreement_as_full_intent(): void
    {
        $prompt = app(PromptBuilder::class)->think($this->project, $this->expert1);

        $this->assertStringContainsString('vollwertige Beitragsabsicht', $prompt);
    }

    public function test_select_prompt_prefers_short_reactions(): void
    {
        $agents = $this->project->contributingExperts()
            ->mapWithKeys(fn (Expert $e) => [$e->id => ['name' => $e->name, 'job' => $e->job, 'prompt_id' => $e->promptId]])
            ->all();

        $prompt = app(PromptBuilder::class)->moderatorSelect($this->project, $agents, [
            $this->expert1->id => 'Zustimmen.',
            $this->expert2->id => 'Neues Argument.',
        ], ['recent_speakers' => [], 'recent_response_types' => []]);

        $this->assertStringContainsString('Kurzreaktions-Präferenz', $prompt);
    }

    public function test_route_attaches_pending_user_to_directive(): void
    {
        $json = '{"candidates":["E'.$this->expert1->id.'"],"directive":{"role":"vertiefen","agenda_step":"divergenz","convergence_intent":"x","address_user":false},"reasoning":"Test."}';

        $client = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->andReturn($json, 'kein json');
        $prompts->shouldReceive('moderatorRoute')->andReturn('prompt');

        $service = new ModeratorService($this->project, $client, $prompts);
        $context = ['pending_user' => 'Was kostet das?', 'pending_user_name' => 'Simon'];

        $result = $service->route('', $context);
        $this->assertSame('Simon', $result['directive']->pendingUserName);
        $this->assertSame('Was kostet das?', $result['directive']->pendingUserExcerpt);

        // JSON fallback path attaches it too.
        $fallback = $service->route('', $context);
        $this->assertSame('Simon', $fallback['directive']->pendingUserName);
    }

    public function test_mention_directive_answers_user_without_handoff(): void
    {
        $service = new ModeratorService(
            $this->project,
            Mockery::mock(OpenAIClient::class),
            Mockery::mock(PromptBuilder::class),
        );

        $directive = $service->mentionDirective([
            'pending_user' => '@Alice wie siehst du das?',
            'pending_user_name' => 'Simon',
        ]);

        $this->assertFalse($directive->addressUser);
        $this->assertSame('Nutzerfrage direkt beantworten', $directive->role);
        $this->assertSame('Simon', $directive->pendingUserName);
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
