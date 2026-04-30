<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\ModeratorService;
use App\Services\OpenAIClient;
use App\Services\PromptBuilder;
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

    public function test_check_triggers_returns_empty_when_no_triggers(): void
    {
        $this->assertSame('', $this->makeService()->checkTriggers());
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

        $this->assertSame('', $this->makeService()->checkTriggers());
    }

    public function test_check_triggers_fires_stagnation_note_at_five_turns(): void
    {
        $this->project->settings = ['topic_turn_count' => 5];
        $this->project->save();

        $note = $this->makeService()->checkTriggers();

        $this->assertStringContainsString('Themenwechsel', $note);
    }

    // -------------------------------------------------------------------------
    // route
    // -------------------------------------------------------------------------

    public function test_route_parses_path_a(): void
    {
        $json = '{"path":"A","addressed_agent":"Alice","selected_agents":[],"reasoning":"Alice wurde angesprochen."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame('A', $result['path']);
        $this->assertSame('Alice', $result['addressed_agent']);
        $this->assertSame([], $result['selected_agents']);
    }

    public function test_route_parses_path_b(): void
    {
        $json = '{"path":"B","addressed_agent":null,"selected_agents":["Alice","Bob"],"reasoning":"Offen."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame('B', $result['path']);
        $this->assertNull($result['addressed_agent']);
        $this->assertContains('Alice', $result['selected_agents']);
        $this->assertContains('Bob', $result['selected_agents']);
    }

    public function test_route_falls_back_to_path_b_on_invalid_json(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('kein gültiges JSON');
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame('B', $result['path']);
        $this->assertNull($result['addressed_agent']);
    }

    public function test_route_parses_json_wrapped_in_markdown_fence(): void
    {
        $response = "```json\n{\"path\":\"B\",\"addressed_agent\":null,\"selected_agents\":[\"Alice\",\"Bob\"],\"reasoning\":\"Test.\"}\n```";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($response);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame('B', $result['path']);
        $this->assertContains('Alice', $result['selected_agents']);
    }

    public function test_route_normalizes_addressed_agent_case(): void
    {
        $json = '{"path":"A","addressed_agent":"alice","selected_agents":[],"reasoning":"Alice wurde angesprochen."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame('A', $result['path']);
        $this->assertSame('Alice', $result['addressed_agent']);
        $this->assertSame([], $result['selected_agents']);
    }

    public function test_route_promotes_single_selected_agent_to_path_a(): void
    {
        $json = '{"path":"B","addressed_agent":null,"selected_agents":["Bob"],"reasoning":"Bob passt fachlich am besten."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))->route();

        $this->assertSame('A', $result['path']);
        $this->assertSame('Bob', $result['addressed_agent']);
        $this->assertSame([], $result['selected_agents']);
    }

    public function test_route_uses_direct_address_hint_when_json_is_invalid(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('kein gültiges JSON');
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        $result = (new ModeratorService($this->project, $client, $prompts))
            ->route('', 'Die letzte Nutzernachricht richtet eine Frage an Bob.');

        $this->assertSame('A', $result['path']);
        $this->assertSame('Bob', $result['addressed_agent']);
        $this->assertSame([], $result['selected_agents']);
    }

    // -------------------------------------------------------------------------
    // selectWinner
    // -------------------------------------------------------------------------

    public function test_select_winner_returns_parsed_winner(): void
    {
        $json = '{"winner":"Bob","reasoning":"Höchste Priorität."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorSelect')->once()->andReturn('prompt');

        $winner = (new ModeratorService($this->project, $client, $prompts))
            ->selectWinner(['Alice' => 'output A', 'Bob' => 'output B']);

        $this->assertSame('Bob', $winner);
    }

    public function test_select_winner_falls_back_when_winner_not_in_candidates(): void
    {
        $json = '{"winner":"Unknown Expert","reasoning":"Test."}';

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts->shouldReceive('moderatorSelect')->once()->andReturn('prompt');

        $winner = (new ModeratorService($this->project, $client, $prompts))
            ->selectWinner(['Alice' => 'output A', 'Bob' => 'output B']);

        $this->assertSame('Alice', $winner);
    }

    public function test_select_winner_falls_back_to_first_key_on_invalid_json(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('ungültig');
        $prompts->shouldReceive('moderatorSelect')->once()->andReturn('prompt');

        $winner = (new ModeratorService($this->project, $client, $prompts))
            ->selectWinner(['Alice' => 'output A', 'Bob' => 'output B']);

        $this->assertSame('Alice', $winner);
    }

    // -------------------------------------------------------------------------
    // updateState
    // -------------------------------------------------------------------------

    public function test_update_state_prepends_speaker_and_type(): void
    {
        $this->project->settings = ['recent_speakers' => ['Bob'], 'recent_response_types' => ['Frage→Antwort']];
        $this->project->save();

        $this->makeService()->updateState($this->expert1, 'Assertion→Reaktion');

        $this->project->refresh();
        $this->assertSame('Alice', $this->project->settings['recent_speakers'][0]);
        $this->assertSame('Bob', $this->project->settings['recent_speakers'][1]);
        $this->assertSame('Assertion→Reaktion', $this->project->settings['recent_response_types'][0]);
    }

    public function test_update_state_keeps_max_six_recent_speakers(): void
    {
        $this->project->settings = ['recent_speakers' => ['A', 'B', 'C', 'D', 'E', 'F']];
        $this->project->save();

        $this->makeService()->updateState($this->expert1, 'type');

        $this->project->refresh();
        $this->assertCount(6, $this->project->settings['recent_speakers']);
        $this->assertSame('Alice', $this->project->settings['recent_speakers'][0]);
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
}
