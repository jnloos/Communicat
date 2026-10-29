<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Jev\JevNotConfigured;
use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\Stages\JevBids;
use App\Discussion\Stages\ThinkAndPrioritize;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\AttributeVisibility;
use App\Discussion\Values\CallStatus;
use App\Discussion\Values\MemoryView;
use App\Discussion\Values\Purpose;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Tests\Fakes\FakeAgents;
use Tests\Fakes\FakeJev;
use Tests\TestCase;

class JevBidsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->project = Project::factory()->create(['seed' => 7]);
        $this->experts = Expert::factory()->withRole('Chief Executive')->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }

        $this->project->setAttributeVisibilities(PersonaAttribute::pluck('id')->mapWithKeys(fn (int $id) => [$id => AttributeVisibility::All])->all());
    }

    private function token(int $index): string
    {
        return $this->experts[$index]->promptId;
    }

    /** The two stages as the pipeline runs them: the agents think and bid, then Jev replaces the bids. */
    private function bidded(): TurnPayload
    {
        return app(ThinkAndPrioritize::class)->handle(
            new TurnPayload($this->project->fresh(), 1),
            fn (TurnPayload $payload) => app(JevBids::class)->handle($payload, fn (TurnPayload $payload) => $payload),
        );
    }

    private function thinkOnly(): TurnPayload
    {
        return app(ThinkAndPrioritize::class)->handle(new TurnPayload($this->project->fresh(), 1), fn (TurnPayload $payload) => $payload);
    }

    public function test_jevs_bid_replaces_the_agents_own_in_the_payload_and_the_own_bid_is_kept_in_the_signals(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 5]);
        FakeJev::urgencies([
            $this->token(0) => [0.0, 0.0, 0.0, 0.0, 1.0],
            $this->token(1) => [0.5, 0.5, 0.0, 0.0, 0.0],
        ]);

        $payload = $this->bidded();

        $this->assertSame(5, $payload->thoughtOf($this->experts[0])->priority);
        $this->assertSame(2, $payload->thoughtOf($this->experts[1])->priority, 'expected level 1.5 rounds to 2');
        $this->assertSame(1, $payload->thoughtOf($this->experts[2])->priority);

        $signals = $payload->selectionSignals();
        $this->assertSame('bid', $signals['jev_kind']);
        $this->assertSame('rounded', $signals['jev_bid_resolution']);
        $this->assertSame([$this->token(0) => 5, $this->token(1) => 5, $this->token(2) => 5], $signals['jev_own_bids']);
        $this->assertSame([$this->token(0) => 5.0, $this->token(1) => 1.5, $this->token(2) => 1.0], $signals['jev_scores']);
        $this->assertSame([$this->token(0) => 0.8, $this->token(1) => 0.8, $this->token(2) => 0.8], $signals['jev_confidences']);
        $this->assertSame([$this->token(0) => 'jev-1.13.0', $this->token(1) => 'jev-1.13.0', $this->token(2) => 'jev-1.13.0'], $signals['jev_models']);
        $this->assertCount(3, $signals['jev_latencies_ms']);
    }

    /** Jev changes the bid and nothing else: the thought, the draft and the short-term memory stay as the team model wrote them. */
    public function test_the_thoughts_and_the_short_term_memory_are_the_team_models_alone(): void
    {
        FakeJev::randomly();
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 3]);

        $thinkOnly = $this->thinkOnly();
        $memory = Summary::where('project_id', $this->project->id)->pluck('content', 'expert_id')->all();
        $bidded = $this->bidded();

        $this->assertSame($memory, Summary::where('project_id', $this->project->id)->pluck('content', 'expert_id')->all());

        foreach ($this->experts as $expert) {
            $this->assertSame($thinkOnly->thoughtOf($expert)->text, $bidded->thoughtOf($expert)->text);
            $this->assertSame($thinkOnly->thoughtOf($expert)->proposal, $bidded->thoughtOf($expert)->proposal);
        }
    }

    public function test_the_agents_own_bid_is_not_needed_and_a_missing_one_is_recorded_as_null(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.']);
        FakeJev::randomly();

        $payload = $this->bidded();

        $this->assertSame([$this->token(0) => null, $this->token(1) => null, $this->token(2) => null], $payload->selectionSignals()['jev_own_bids']);
        $this->assertNotNull($payload->thoughtOf($this->experts[0])->priority);
    }

    public function test_each_request_is_that_agents_own_view_with_only_its_own_thought(): void
    {
        $thoughts = ['GEDANKE-ALPHA', 'GEDANKE-BETA', 'GEDANKE-GAMMA'];
        $call = 0;
        ThinkAgent::fake(function () use (&$call, $thoughts) {
            return ['thought' => $thoughts[$call++], 'priority' => 3];
        });
        FakeJev::randomly();

        $this->bidded();

        $this->assertCount(3, FakeJev::prompts());

        foreach ($this->experts as $index => $expert) {
            $state = collect(FakeJev::prompts())->map->state->first(fn (string $state) => str_contains($state, "(your token: {$expert->promptId})"));

            $this->assertNotNull($state, "a request for expert {$index}");
            $this->assertStringContainsString($thoughts[$index], $state);

            foreach (array_diff($thoughts, [$thoughts[$index]]) as $other) {
                $this->assertStringNotContainsString($other, $state);
            }

            $this->assertStringContainsString("{$expert->name} [{$expert->promptId}] (Chief Executive)", $state);
            $this->assertStringContainsString('Your role is Chief Executive.', $state);
        }
    }

    public function test_the_question_is_one_urgency_score_on_five_levels_asked_in_the_voice_of_the_state(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 3]);
        FakeJev::randomly();

        $this->bidded();

        foreach (FakeJev::prompts() as $prompt) {
            $question = $prompt->questions['urgency'] ?? null;

            $this->assertCount(1, $prompt->questions);
            $this->assertInstanceOf(Score::class, $question);
            $this->assertCount(5, $question->levels);
            $this->assertStringContainsString('do you want', $question->instructions, 'the state speaks to "you", so does the question');
        }
    }

    /** The bid prompt is the team's bidding prompt up to the agent's own thought: one source for the persona, topic, roster and memory. */
    public function test_the_state_is_the_bidding_prompt_without_its_task_and_with_the_thought_in_place_of_the_stored_memory(): void
    {
        $data = [
            'expert' => $this->experts[0], 'project' => $this->project->fresh(), 'experts' => $this->project->contributingExperts(),
            'memory' => new MemoryView([['position' => null, 'name' => 'Alice', 'token' => 'E1', 'addressee' => null, 'content' => 'Ein Beitrag.']], 'GESPEICHERTER GEDANKE', 'Eine Zusammenfassung.'),
            'thought' => 'GEDANKE VON JETZT', 'lowest' => 1, 'highest' => 5,
        ];
        $team = app(PromptRenderer::class)->render('prompts.think.prioritize', $data);
        $bid = app(PromptRenderer::class)->render('prompts.jev.bid', $data);

        $shared = Str::before($team, "\n\n=== YOUR SHORT-TERM MEMORY");

        $this->assertStringContainsString('=== ABOUT YOU ===', $shared);
        $this->assertStringContainsString('=== LONG-TERM MEMORY', $shared);
        $this->assertStringContainsString('Ein Beitrag.', $shared);
        $this->assertStringStartsWith($shared, $bid);
        $this->assertSame($shared."\n\n=== YOUR THOUGHT RIGHT NOW (visible only to you) ===\nGEDANKE VON JETZT", $bid);
        $this->assertStringNotContainsString('GESPEICHERTER GEDANKE', $bid);
        $this->assertStringNotContainsString('=== TASK ===', $bid);
    }

    public function test_every_request_is_recorded_as_a_think_call_of_that_expert(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 3]);
        FakeJev::randomly();

        $this->bidded();

        $jev = PromptLog::where('provider', 'typesafe')->get();
        $this->assertCount(3, $jev);
        $this->assertEqualsCanonicalizing(array_map(fn (Expert $e) => $e->id, $this->experts), $jev->pluck('expert_id')->all());
        $this->assertSame([Purpose::Think], $jev->pluck('purpose')->unique()->all());
        $this->assertSame([CallStatus::Ok], $jev->pluck('status')->unique()->all());
        $this->assertSame(3, PromptLog::where('provider', '!=', 'typesafe')->orWhereNull('provider')->count(), 'the three think calls of the team model are separate rows');
    }

    public function test_an_unreadable_or_failed_jev_bid_is_a_missing_bid_for_the_selector_to_handle(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 5]);
        FakeJev::answering(fn () => ['urgency' => new ChoiceAnswer('x', ['x' => 1.0])]);

        $payload = $this->bidded();

        foreach ($this->experts as $expert) {
            $this->assertNull($payload->thoughtOf($expert)->priority);
        }
        $this->assertSame([$this->token(0) => null, $this->token(1) => null, $this->token(2) => null], $payload->selectionSignals()['jev_scores']);
        $this->assertSame([$this->token(0) => 5, $this->token(1) => 5, $this->token(2) => 5], $payload->selectionSignals()['jev_own_bids'], 'the agents bids survive in the signals');

        FakeJev::fails(ProviderConnectionException::forProvider('typesafe'));
        $failed = $this->bidded();

        $this->assertNull($failed->thoughtOf($this->experts[0])->priority);
        $this->assertSame(3, PromptLog::where('status', CallStatus::Failed)->count());
    }

    public function test_the_unchanged_selector_reads_jevs_bids_and_treats_a_missing_one_as_it_always_did(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 5]);
        FakeJev::urgencies([
            $this->token(1) => [0.0, 0.0, 0.0, 1.0, 0.0],
            $this->token(2) => [0.0, 0.0, 1.0, 0.0, 0.0],
        ]);
        $payload = $this->bidded();

        $selection = app(HighestBidSelector::class)->select($payload);

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame([$this->token(0) => 1, $this->token(1) => 4, $this->token(2) => 3], $selection->signals['bids']);
        $this->assertFalse($selection->tieBroken);
    }

    public function test_equal_jev_bids_are_a_tie_for_the_tie_breaker_whatever_the_agents_bid_themselves(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 5]);
        FakeJev::urgencies([]);
        $payload = $this->bidded();

        $selection = app(HighestBidSelector::class)->select($payload);

        $this->assertTrue($selection->tieBroken);
    }

    public function test_without_a_key_the_stage_refuses_before_it_asks_jev(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 5]);
        FakeJev::randomly();
        config(['ai.providers.typesafe.key' => '']);

        try {
            $this->bidded();
            $this->fail('A missing key must not be swallowed.');
        } catch (JevNotConfigured) {
            $this->assertSame([], FakeJev::prompts());
        }
    }

    public function test_a_started_run_bids_with_the_frozen_decision_model_and_not_the_live_config(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 3]);
        FakeJev::randomly();
        $this->project->update(['run_config' => ['decision_model' => ['provider' => 'typesafe', 'model' => 'jev-frozen-0.9', 'decides' => 'bidder', 'timeout' => 17, 'bid_resolution' => 'rounded']]]);
        config(['ai.jev.model' => 'jev-live-2.0', 'ai.jev.timeout' => 99]);

        $this->bidded();

        foreach (FakeJev::prompts() as $prompt) {
            $this->assertSame('jev-frozen-0.9', $prompt->model);
            $this->assertSame(17, $prompt->timeout);
        }
        $this->assertSame(['jev-frozen-0.9'], PromptLog::where('provider', 'typesafe')->pluck('model')->unique()->values()->all());
    }
}
