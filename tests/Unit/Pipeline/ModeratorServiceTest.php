<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ModeratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Expert $expert1;
    private Expert $expert2;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn() => Project::create([
            'title'       => 'Test',
            'description' => 'Test',
            'settings'    => [],
            'user_id'     => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
    }

    private function makeService(): ModeratorService
    {
        return new ModeratorService(
            $this->project,
            Mockery::mock(OpenAIClient::class),
            Mockery::mock(PromptBuilder::class)
        );
    }

    // -------------------------------------------------------------------------
    // checkTriggers
    // -------------------------------------------------------------------------

    public function test_check_triggers_returns_only_agenda_note_when_no_silence(): void
    {
        // With no silence counters the note carries just the current agenda phase.
        $note = $this->makeService()->checkTriggers();

        $this->assertStringContainsString('Divergenz', $note);
        $this->assertStringNotContainsString('nicht geäußert', $note);
    }

    public function test_check_triggers_fires_silence_note_at_two_turns(): void
    {
        $this->project->settings = ['silence_counters' => [$this->expert1->id => 2]];
        $this->project->save();

        $note = $this->makeService()->checkTriggers();

        $this->assertStringContainsString('Alice', $note);
        $this->assertStringContainsString('nicht geäußert', $note);
    }

    public function test_check_triggers_does_not_fire_silence_at_one_turn(): void
    {
        $this->project->settings = ['silence_counters' => [$this->expert1->id => 1]];
        $this->project->save();

        $this->assertStringNotContainsString('nicht geäußert', $this->makeService()->checkTriggers());
    }

    public function test_check_triggers_nudges_toward_convergence_late_in_phase(): void
    {
        $this->project->settings = ['agenda_phase' => 'divergenz', 'phase_turn_count' => 5];
        $this->project->save();

        $this->assertStringContainsString('Konvergenz', $this->makeService()->checkTriggers());
    }

    // -------------------------------------------------------------------------
    // route
    // -------------------------------------------------------------------------

    public function test_route_returns_candidate_ids_from_json(): void
    {
        $json = '{"candidates":["E' . $this->expert2->id . '"],"directive":{"role":"vertiefen","agenda_step":"divergenz","convergence_intent":"x","address_user":false},"reasoning":"Bob passt."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame([$this->expert2->id], $result['candidates']);
        $this->assertSame('vertiefen', $result['directive']->role);
    }

    public function test_route_filters_unknown_ids(): void
    {
        $json = '{"candidates":["E' . $this->expert1->id . '","E999999"],"directive":{},"reasoning":"x"}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame([$this->expert1->id], $result['candidates']);
    }

    public function test_route_falls_back_to_all_contributors_on_invalid_json(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('kein gültiges JSON');
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertEqualsCanonicalizing(
            [$this->expert1->id, $this->expert2->id],
            $result['candidates']
        );
    }

    public function test_route_parses_candidate_ids_in_markdown_fence(): void
    {
        $response = "```json\n{\"candidates\":[\"E" . $this->expert1->id . '","E' . $this->expert2->id . "\"],\"directive\":{},\"reasoning\":\"Test.\"}\n```";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($response);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertContains($this->expert1->id, $result['candidates']);
        $this->assertContains($this->expert2->id, $result['candidates']);
    }

    public function test_route_falls_back_to_all_when_candidates_empty(): void
    {
        $json = '{"candidates":[],"directive":{},"reasoning":"x"}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertEqualsCanonicalizing(
            [$this->expert1->id, $this->expert2->id],
            $result['candidates']
        );
    }

    // -------------------------------------------------------------------------
    // selectWinner
    // -------------------------------------------------------------------------

    /** @return array<int, array{memory: string, beitragsabsicht: string}> */
    private function thinkOutputs(): array
    {
        return [
            $this->expert1->id => ['memory' => '', 'beitragsabsicht' => 'output A'],
            $this->expert2->id => ['memory' => '', 'beitragsabsicht' => 'output B'],
        ];
    }

    public function test_select_winner_returns_parsed_winner(): void
    {
        $json = '{"winner":"E' . $this->expert2->id . '","reasoning":"Höchste Priorität."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorSelect')->once()->andReturn('prompt');

        $winner = (new ModeratorService($this->project, $client, $prompts))
            ->selectWinner($this->thinkOutputs());

        $this->assertSame($this->expert2->id, $winner);
    }

    public function test_select_winner_falls_back_when_winner_not_in_candidates(): void
    {
        $json = '{"winner":"E999999","reasoning":"Test."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorSelect')->once()->andReturn('prompt');

        $winner = (new ModeratorService($this->project, $client, $prompts))
            ->selectWinner($this->thinkOutputs());

        $this->assertSame($this->expert1->id, $winner);
    }

    public function test_select_winner_falls_back_to_first_key_on_invalid_json(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('ungültig');
        $prompts->shouldReceive('moderatorSelect')->once()->andReturn('prompt');

        $winner = (new ModeratorService($this->project, $client, $prompts))
            ->selectWinner($this->thinkOutputs());

        $this->assertSame($this->expert1->id, $winner);
    }

    // -------------------------------------------------------------------------
    // updateState
    // -------------------------------------------------------------------------

    public function test_update_state_prepends_speaker_and_type(): void
    {
        $this->project->settings = ['recent_speakers' => [$this->expert2->id], 'recent_response_types' => ['Frage→Antwort']];
        $this->project->save();

        $this->makeService()->updateState($this->expert1, 'Assertion→Reaktion');

        $this->project->refresh();
        $this->assertSame($this->expert1->id, $this->project->settings['recent_speakers'][0]);
        $this->assertSame($this->expert2->id, $this->project->settings['recent_speakers'][1]);
        $this->assertSame('Assertion→Reaktion', $this->project->settings['recent_response_types'][0]);
    }

    public function test_update_state_keeps_max_six_recent_speakers(): void
    {
        $this->project->settings = ['recent_speakers' => [91, 92, 93, 94, 95, 96]];
        $this->project->save();

        $this->makeService()->updateState($this->expert1, 'type');

        $this->project->refresh();
        $this->assertCount(6, $this->project->settings['recent_speakers']);
        $this->assertSame($this->expert1->id, $this->project->settings['recent_speakers'][0]);
    }

    public function test_update_state_increments_all_silence_counters_and_resets_winner(): void
    {
        $this->project->settings = [
            'silence_counters' => [$this->expert1->id => 2, $this->expert2->id => 1],
        ];
        $this->project->save();

        $this->makeService()->updateState($this->expert1, 'type');

        $this->project->refresh();
        $counters = $this->project->settings['silence_counters'];
        $this->assertSame(0, $counters[$this->expert1->id]);
        $this->assertSame(2, $counters[$this->expert2->id]);
    }

    public function test_update_state_initialises_counters_for_new_experts(): void
    {
        $this->makeService()->updateState($this->expert1, 'type');

        $this->project->refresh();
        $counters = $this->project->settings['silence_counters'];
        $this->assertSame(0, $counters[$this->expert1->id]);
        $this->assertSame(1, $counters[$this->expert2->id]);
    }

    public function test_update_state_records_recent_opening_for_winner(): void
    {
        $content = "Aus architektonischer Sicht ist das problematisch.\nWeitere Details folgen.";

        $this->makeService()->updateState($this->expert1, 'Frage→Antwort', $content);

        $this->project->refresh();
        $openings = $this->project->settings['recent_openings'][$this->expert1->id] ?? [];
        $this->assertCount(1, $openings);
        $this->assertStringStartsWith('Aus architektonischer Sicht', $openings[0]);
    }

    public function test_update_state_caps_recent_openings_per_expert_at_three(): void
    {
        $service = $this->makeService();

        $service->updateState($this->expert1, 'type', 'Erstens kommt die Idee.');
        $service->updateState($this->expert1, 'type', 'Zweitens muss man messen.');
        $service->updateState($this->expert1, 'type', 'Drittens braucht es Tests.');
        $service->updateState($this->expert1, 'type', 'Viertens dann erst skalieren.');

        $this->project->refresh();
        $openings = $this->project->settings['recent_openings'][$this->expert1->id] ?? [];
        $this->assertCount(3, $openings);
        $this->assertStringStartsWith('Viertens', $openings[0]);
        $this->assertStringStartsWith('Drittens', $openings[1]);
        $this->assertStringStartsWith('Zweitens', $openings[2]);
    }

    public function test_update_state_skips_recent_openings_when_content_empty(): void
    {
        $this->makeService()->updateState($this->expert1, 'type', '');

        $this->project->refresh();
        $this->assertArrayNotHasKey('recent_openings', $this->project->settings ?? []);
    }
}
