<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Support\ProgressTracker;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProgressTrackerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
    }

    // -------------------------------------------------------------------------
    // Deterministic stagnation
    // -------------------------------------------------------------------------

    public function test_repeated_turn_bumps_stagnation_and_new_content_resets_it(): void
    {
        $tracker = new ProgressTracker($this->project);
        $settings = [];

        $settings = $tracker->recordTurn($settings, 'Skalierbarkeit beginnt beim Design verteilter Systeme');
        $this->assertSame(0, $settings['stagnation_counter']);

        // Same significant words again → high overlap → stagnation rises.
        $settings = $tracker->recordTurn($settings, 'Skalierbarkeit beginnt beim Design verteilter Systeme');
        $this->assertSame(1, $settings['stagnation_counter']);

        $settings = $tracker->recordTurn($settings, 'Skalierbarkeit beginnt beim Design verteilter Systeme');
        $this->assertSame(2, $settings['stagnation_counter']);

        // Genuinely new content → reset.
        $settings = $tracker->recordTurn($settings, 'Budget entscheidet Zeitplan Personal Ressourcen Priorisierung');
        $this->assertSame(0, $settings['stagnation_counter']);
    }

    public function test_record_turn_appends_covered_point_and_advances_interval_counter(): void
    {
        $tracker = new ProgressTracker($this->project);

        $settings = $tracker->recordTurn([], 'Wir sollten zuerst die Latenz messen', 'Latenz messen als ersten Schritt');

        $this->assertNotEmpty($settings['covered_points']);
        $this->assertStringContainsString('Latenz messen', $settings['covered_points'][0]);
        $this->assertSame(1, $settings['turns_since_closure_check']);
    }

    public function test_jaccard_and_fingerprint_ignore_stopwords_and_short_words(): void
    {
        $tracker = new ProgressTracker($this->project);

        // "und", "der", "ist" are stopwords; "ein" is short — none should count.
        $fp = $tracker->fingerprint('Der Cache ist und ein Performance Faktor');
        $this->assertContains('cache', $fp);
        $this->assertContains('performance', $fp);
        $this->assertNotContains('der', $fp);
        $this->assertNotContains('und', $fp);

        $this->assertSame(1.0, $tracker->jaccard(['cache', 'latenz'], ['latenz', 'cache']));
        $this->assertSame(0.0, $tracker->jaccard(['cache'], ['budget']));
    }

    // -------------------------------------------------------------------------
    // Periodic LLM closure check
    // -------------------------------------------------------------------------

    public function test_signals_runs_closure_check_when_interval_due_and_persists_verdict(): void
    {
        $this->project->settings = ['turns_since_closure_check' => 4];
        $this->project->save();

        $json = '{"point_resolved":true,"going_in_circles":false,"next_move":"abschluss","open_question":"Wie messen wir Erfolg?","zwischenergebnis":"Einigkeit über die Messgröße."}';

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorClosure')->once()->andReturn('closure-prompt');
        $this->app->instance(OpenAIClient::class, $client);
        $this->app->instance(PromptBuilder::class, $prompts);

        $signals = (new ProgressTracker($this->project))->signals();

        $this->assertTrue($signals['closure_due']);
        $this->assertTrue($signals['point_resolved']);
        $this->assertSame('abschluss', $signals['next_move']);
        $this->assertSame('Wie messen wir Erfolg?', $signals['open_question']);

        $this->project->refresh();
        $this->assertTrue($this->project->settings['closure_advance']);
        $this->assertSame(0, $this->project->settings['turns_since_closure_check']);
        $this->assertNotEmpty($this->project->settings['resolved_points']);
    }

    public function test_signals_skips_llm_when_not_due(): void
    {
        $this->project->settings = ['turns_since_closure_check' => 1, 'stagnation_counter' => 0];
        $this->project->save();

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldNotReceive('sendFast');
        $this->app->instance(OpenAIClient::class, $client);

        $signals = (new ProgressTracker($this->project))->signals();

        $this->assertFalse($signals['closure_due']);
    }

    // -------------------------------------------------------------------------
    // Stronger stale-topic detection (turns_on_topic streak)
    // -------------------------------------------------------------------------

    public function test_record_turn_increments_topic_streak(): void
    {
        $tracker = new ProgressTracker($this->project);

        $settings = $tracker->recordTurn([], 'Erster Punkt zur Latenz');
        $this->assertSame(1, $settings['turns_on_topic']);

        $settings = $tracker->recordTurn($settings, 'Zweiter ganz anderer Punkt Budget');
        $this->assertSame(2, $settings['turns_on_topic']);
    }

    public function test_stale_topic_forces_advance_even_without_llm_verdict(): void
    {
        // Topic has run long enough to be stale; the closure LLM returns garbage.
        config(['discussion.topic_stale_threshold' => 6]);
        $this->project->settings = ['turns_on_topic' => 6, 'turns_since_closure_check' => 0];
        $this->project->save();

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn('not json at all');
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorClosure')->once()->andReturn('closure-prompt');
        $this->app->instance(OpenAIClient::class, $client);
        $this->app->instance(PromptBuilder::class, $prompts);

        $signals = (new ProgressTracker($this->project))->signals();

        $this->assertTrue($signals['topic_stale']);
        $this->assertTrue($signals['closure_due']);
        $this->assertTrue($signals['going_in_circles']);

        // A stale topic advances the agenda and resets the topic streak.
        $this->project->refresh();
        $this->assertTrue($this->project->settings['closure_advance']);
        $this->assertSame(0, $this->project->settings['turns_on_topic']);
    }

    // -------------------------------------------------------------------------
    // Persona-driven topic-done signal
    // -------------------------------------------------------------------------

    public function test_record_topic_votes_accumulates_and_withdraws(): void
    {
        $tracker = new ProgressTracker($this->project);

        $tracker->recordTopicVotes([7 => true, 8 => false]);
        $this->project->refresh();
        $this->assertSame([7 => true], $this->project->settings['topic_done_votes']);

        // Expert 8 now agrees, expert 7 changed their mind.
        $tracker->recordTopicVotes([7 => false, 8 => true]);
        $this->project->refresh();
        $this->assertSame([8 => true], $this->project->settings['topic_done_votes']);
    }

    public function test_persona_consensus_forces_resolution(): void
    {
        // Two-expert panel, quorum 0.6 → both must vote done (ceil(2*0.6)=2).
        config(['discussion.topic_done_quorum' => 0.6]);
        $e1 = Expert::factory()->create();
        $e2 = Expert::factory()->create();
        $this->project->addContributingExpert($e1);
        $this->project->addContributingExpert($e2);

        $this->project->settings = [
            'turns_since_closure_check' => 0,
            'topic_done_votes' => [$e1->id => true, $e2->id => true],
        ];
        $this->project->save();

        $json = '{"point_resolved":false,"going_in_circles":false,"next_move":"vertiefen","open_question":"Q","zwischenergebnis":"Gemeinsame Linie steht."}';
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorClosure')->once()->andReturn('closure-prompt');
        $this->app->instance(OpenAIClient::class, $client);
        $this->app->instance(PromptBuilder::class, $prompts);

        $signals = (new ProgressTracker($this->project))->signals();

        $this->assertTrue($signals['persona_done']);
        $this->assertSame(2, $signals['persona_done_votes']);
        // Persona consensus overrides the LLM's "vertiefen" into a resolution.
        $this->assertTrue($signals['point_resolved']);

        $this->project->refresh();
        $this->assertTrue($this->project->settings['closure_advance']);
        $this->assertSame([], $this->project->settings['topic_done_votes']);
    }

    public function test_single_done_vote_is_below_quorum(): void
    {
        config(['discussion.topic_done_quorum' => 0.6]);
        $e1 = Expert::factory()->create();
        $e2 = Expert::factory()->create();
        $this->project->addContributingExpert($e1);
        $this->project->addContributingExpert($e2);

        $this->project->settings = [
            'turns_since_closure_check' => 1,
            'stagnation_counter' => 0,
            'turns_on_topic' => 1,
            'topic_done_votes' => [$e1->id => true],
        ];
        $this->project->save();

        // Not due (one vote below quorum, no interval/stagnation/stale trigger).
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldNotReceive('sendFast');
        $this->app->instance(OpenAIClient::class, $client);

        $signals = (new ProgressTracker($this->project))->signals();

        $this->assertFalse($signals['persona_done']);
        $this->assertSame(1, $signals['persona_done_votes']);
        $this->assertFalse($signals['closure_due']);
    }

    public function test_signals_forces_check_when_stagnation_threshold_reached(): void
    {
        $this->project->settings = ['turns_since_closure_check' => 0, 'stagnation_counter' => 3];
        $this->project->save();

        $json = '{"point_resolved":false,"going_in_circles":true,"next_move":"neuer_aspekt","open_question":"Q","zwischenergebnis":""}';

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorClosure')->once()->andReturn('closure-prompt');
        $this->app->instance(OpenAIClient::class, $client);
        $this->app->instance(PromptBuilder::class, $prompts);

        $signals = (new ProgressTracker($this->project))->signals();

        $this->assertTrue($signals['closure_due']);
        $this->assertTrue($signals['going_in_circles']);

        // Circling advances the agenda and resets stagnation.
        $this->project->refresh();
        $this->assertTrue($this->project->settings['closure_advance']);
        $this->assertSame(0, $this->project->settings['stagnation_counter']);
    }
}
