<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PipelineModeratorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    private Expert $expert2;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test Project',
            'description' => 'Test',
            'settings' => [],
            'user_id' => $this->user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
        $this->project->addMessage('Hallo, wie seht ihr das?', $this->user);
    }

    private function mockPrompts(): PromptBuilder
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('moderatorSelect')->andReturn('select-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        return $prompts;
    }

    private function routeJson(array $candidateTokens, bool $handBackToUser = false): string
    {
        return json_encode([
            'candidates' => $candidateTokens,
            'directive' => [
                'role' => 'vertiefen',
                'agenda_step' => 'divergenz',
                'convergence_intent' => 'x',
                'hand_back_to_user' => $handBackToUser,
            ],
            'reasoning' => 'Test.',
        ]);
    }

    public function test_single_candidate_turn_persists_partner_from_speak_trailer(): void
    {
        $client = Mockery::mock(OpenAIClient::class);
        // route → speak (single candidate skips the select call)
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}"]),
            "Alice antwortet dir, Bob.\n---STEUERUNG---\nADRESSAT: E{$this->expert2->id}\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nLäuft.\nBEITRAGSABSICHT: Ein Beispiel bringen.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
        $this->assertSame('Alice antwortet dir, Bob.', $msg->content);
        $this->assertSame('Frage→Antwort', $msg->adjacency_pair_type);
        $this->assertSame(Expert::class, $msg->adjacency_partner_type);
        $this->assertSame($this->expert2->id, $msg->adjacency_partner_id);
        $this->assertFalse($msg->handsBackToUser());
    }

    public function test_hand_back_to_user_lands_when_the_text_asks_the_user(): void
    {
        // The user's opening message must already be answered — an unanswered
        // one clamps the hand-off (see the clamp test below).
        $this->project->addMessage('Erste Einschätzung.', $this->expert1);

        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        // route (handBackToUser true) → select → speak
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}", "E{$this->expert2->id}"], handBackToUser: true),
            json_encode(['winner' => "E{$this->expert2->id}", 'reasoning' => 'r']),
            "Welche Variante bevorzugst du?\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        $result = (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->expert2->id, $msg->expert_id);
        $this->assertTrue($msg->handsBackToUser());
        $this->assertSame(User::class, $msg->adjacency_partner_type);
        $this->assertSame($this->user->id, $msg->adjacency_partner_id);
        $this->assertSame(Message::PAIR_ABSCHLUSS_NUTZER, $msg->adjacency_pair_type);

        $this->assertTrue($result['stop']);
        $this->assertSame('user_addressed', $result['reason']);
        $this->assertSame($this->user->id, $result['user_id']);
    }

    /**
     * The moderator asked to hand back while the user's own message was still
     * unanswered. Replying "your turn" to someone who just asked something is a
     * non-answer, so the clamp keeps the floor with the experts. This was the
     * single most common failure: 14 of 18 logged hand-offs sat on top of an
     * unanswered user message.
     */
    public function test_hand_back_is_clamped_while_a_user_message_is_unanswered(): void
    {
        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}", "E{$this->expert2->id}"], handBackToUser: true),
            json_encode(['winner' => "E{$this->expert2->id}", 'reasoning' => 'r']),
            "Welche Variante bevorzugst du?\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        $result = (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertFalse($msg->handsBackToUser());
        $this->assertFalse($result['stop']);
    }

    /**
     * The reported defect: the moderator wants to hand back, but the agent's
     * visible text asks a named expert a question. Handing to the user here
     * strands the addressed expert — nobody ever answers them. The text wins,
     * the pair is recorded against the expert, and the round keeps running.
     */
    public function test_hand_back_is_overruled_when_the_text_addresses_an_expert(): void
    {
        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}", "E{$this->expert2->id}"], handBackToUser: true),
            json_encode(['winner' => "E{$this->expert2->id}", 'reasoning' => 'r']),
            "Wie siehst du das?\n---STEUERUNG---\nADRESSAT: E{$this->expert1->id}\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        $result = (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertFalse($msg->handsBackToUser());
        $this->assertSame(Expert::class, $msg->adjacency_partner_type);
        $this->assertSame($this->expert1->id, $msg->adjacency_partner_id);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $msg->adjacency_pair_type);

        $this->assertFalse($result['stop']);
    }

    /**
     * A hand-off whose text poses no question leaves the user with nothing to
     * answer, so the round continues rather than stalling on a dead end.
     */
    public function test_hand_back_without_a_question_does_not_stop_the_round(): void
    {
        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}", "E{$this->expert2->id}"], handBackToUser: true),
            json_encode(['winner' => "E{$this->expert2->id}", 'reasoning' => 'r']),
            "Damit ist der Punkt festgehalten.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        $result = (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertFalse($msg->handsBackToUser());
        $this->assertFalse($result['stop']);
    }

    /**
     * The agent asked a peer a question in prose but left ADRESSAT on "none"
     * (or dropped the trailer entirely). The addressee is recovered from the
     * text so the pair can be closed next turn.
     */
    public function test_addressee_is_recovered_from_prose_when_the_trailer_is_missing(): void
    {
        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}", "E{$this->expert2->id}"]),
            json_encode(['winner' => "E{$this->expert2->id}", 'reasoning' => 'r']),
            'Das trägt nur mit Zahlen, Alice — welche Quote hältst du für belastbar?'
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame(Expert::class, $msg->adjacency_partner_type);
        $this->assertSame($this->expert1->id, $msg->adjacency_partner_id);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $msg->adjacency_pair_type);
    }

    public function test_user_inclusion_cadence_forces_handoff_even_when_route_says_false(): void
    {
        config(['discussion.user_inclusion_multiplier' => 2]);

        foreach ([$this->expert1, $this->expert2, $this->expert1, $this->expert2] as $expert) {
            $this->project->addMessage('Expertenbeitrag', $expert);
        }

        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: Nutzer fragen.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}"], handBackToUser: false),
            "Alice fragt nach deiner Präferenz?\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendSlow')->once()->andReturn($thinkResponse);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        $result = (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertTrue($msg->handsBackToUser());
        $this->assertTrue($result['stop']);
        $this->assertSame('user_addressed', $result['reason']);
    }

    public function test_user_mention_skips_route_and_select(): void
    {
        $this->project->addMessage('@Alice wie siehst du das?', $this->user);

        $client = Mockery::mock(OpenAIClient::class);
        // Exactly one sendFast call (speak) proves route AND select were skipped.
        $client->shouldReceive('sendFast')->once()->andReturn(
            "Alice antwortet direkt.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: Dem Nutzer antworten.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
        $this->assertSame('Alice antwortet direkt.', $msg->content);
    }

    public function test_mentioned_expert_may_speak_back_to_back(): void
    {
        $this->project->addMessage('Erster Beitrag von Alice', $this->expert1);
        $this->project->settings = ['recent_speakers' => [$this->expert1->id]];
        $this->project->save();
        $this->project->addMessage('@Alice kannst du das vertiefen?', $this->user);

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn(
            "Alice vertieft.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: Vertiefen.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
    }

    public function test_multiple_mentions_run_select_with_back_to_back_exemption(): void
    {
        $this->project->settings = ['recent_speakers' => [$this->expert1->id]];
        $this->project->save();
        $this->project->addMessage('@Alice und @Bob, was meint ihr beide?', $this->user);

        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: Antworten.";

        $client = Mockery::mock(OpenAIClient::class);
        // select → speak (no route call: the mention shortcut chose the candidates)
        $client->shouldReceive('sendFast')->twice()->andReturn(
            json_encode(['winner' => "E{$this->expert1->id}", 'reasoning' => 'r']),
            "Alice antwortet beiden.\n---STEUERUNG---\nADRESSAT: E{$this->expert2->id}\nPAARTYP: Frage→Antwort"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        // Despite being the previous speaker, the mentioned winner is kept.
        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
    }

    public function test_mention_of_non_contributor_uses_normal_funnel(): void
    {
        $this->project->addMessage('@Zoe was denkst du?', $this->user);

        $client = Mockery::mock(OpenAIClient::class);
        // route → speak: the unknown mention falls back to the moderator funnel.
        $client->shouldReceive('sendFast')->twice()->andReturn(
            $this->routeJson(["E{$this->expert1->id}"]),
            "Alice übernimmt.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: Antworten.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
    }

    public function test_turn_updates_project_state(): void
    {
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->expert1->id}"]),
            "Alice verdichtet.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Synthese→Diskussion"
        );
        $client->shouldReceive('sendSlow')->andReturn(
            "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y."
        );

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $this->project->refresh();
        $settings = $this->project->settings;
        $this->assertSame($this->expert1->id, $settings['recent_speakers'][0]);
        $this->assertSame('Synthese→Diskussion', $settings['recent_response_types'][0]);
        $this->assertSame(0, $settings['silence_counters'][$this->expert1->id]);
        $this->assertGreaterThan(0, $settings['silence_counters'][$this->expert2->id]);
    }
}
