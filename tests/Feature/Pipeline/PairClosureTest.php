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

/**
 * Closing an adjacency pair.
 *
 * Of the eight pairs closed in the logged production runs exactly one answered
 * back to the asker; three immediately addressed a third person instead, so the
 * transcript read as a chain rather than as question and answer. The addressee
 * now wins deterministically and the closure is recorded, while chaining on to
 * somebody else stays allowed — both ends are stored.
 */
class PairClosureTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $asker;

    private Expert $addressee;

    private Expert $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
        $this->asker = Expert::factory()->create(['name' => 'Sophie Wagner']);
        $this->addressee = Expert::factory()->create(['name' => 'Jan Lehmann']);
        $this->bystander = Expert::factory()->create(['name' => 'Lena Fischer']);

        foreach ([$this->asker, $this->addressee, $this->bystander] as $expert) {
            $this->project->addContributingExpert($expert);
        }

        $this->project->addMessage('Wie geht ihr das an?', $user);
    }

    private function openPair(): Message
    {
        $message = $this->project->addMessage(
            'Das trägt nur mit Werkzeugen. Welche digitalen Tools hältst du für am wirkungsvollsten, Jan?',
            $this->asker,
        );
        $message->adjacency_pair_type = Message::PAIR_FRAGE_ANTWORT;
        $message->adjacency_partner_type = Expert::class;
        $message->adjacency_partner_id = $this->addressee->id;
        $message->save();

        return $message;
    }

    private function mockPrompts(): PromptBuilder
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('moderatorSelect')->andReturn('select-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        return $prompts;
    }

    private function routeJson(array $tokens): string
    {
        return json_encode([
            'candidates' => $tokens,
            'directive' => [
                'role' => 'vertiefen',
                'agenda_step' => 'divergenz',
                'convergence_intent' => 'x',
                'hand_back_to_user' => false,
            ],
            'reasoning' => 'Test.',
        ]);
    }

    /**
     * The moderator's own pick is overruled: whoever was asked answers.
     */
    public function test_the_addressee_wins_even_when_the_selector_prefers_someone_else(): void
    {
        $this->openPair();

        $think = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->addressee->id}", "E{$this->bystander->id}"]),
            // The selector votes for the bystander — the floor rule must win.
            json_encode(['winner' => "E{$this->bystander->id}", 'reasoning' => 'r']),
            "Vor allem die automatisierte Abnahme.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->addressee->id => $think,
            $this->bystander->id => $think,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $latest = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->addressee->id, $latest->expert_id);
    }

    public function test_the_closure_is_recorded_on_the_answering_message(): void
    {
        $pair = $this->openPair();
        $this->runTurnWithSpeak("Vor allem die automatisierte Abnahme, Sophie.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");

        $latest = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($pair->id, $latest->answers_message_id);
        $this->assertSame($this->addressee->id, $latest->expert_id);
    }

    /**
     * The behaviour the user explicitly wants to keep: answering and passing on
     * in one turn. Both ends must survive, so the pair stays readable.
     */
    public function test_a_turn_can_close_one_pair_and_open_another(): void
    {
        $pair = $this->openPair();
        $this->runTurnWithSpeak("Da bin ich ganz bei dir. Wie siehst du das, Lena?\n---STEUERUNG---\nADRESSAT: E{$this->bystander->id}\nPAARTYP: Frage→Antwort");

        $latest = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();

        $this->assertSame($pair->id, $latest->answers_message_id, 'closes the pair with Sophie');
        $this->assertSame($this->bystander->id, $latest->adjacency_partner_id, 'opens a new pair with Lena');
        $this->assertSame(Expert::class, $latest->adjacency_partner_type);
    }

    public function test_an_answered_pair_does_not_force_the_same_expert_again(): void
    {
        $pair = $this->openPair();
        $this->runTurnWithSpeak("Vor allem die automatisierte Abnahme.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");

        $this->assertNotNull($pair->fresh()->answeredBy);
    }

    /**
     * Run one turn where the addressee is the only candidate the funnel offers,
     * so the floor rule and the single-candidate path both lead to them.
     */
    private function runTurnWithSpeak(string $speakResponse): void
    {
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $this->routeJson(["E{$this->addressee->id}"]),
            $speakResponse
        );
        $client->shouldReceive('sendSlow')->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.");
        $client->shouldReceive('sendManySlow')->andReturn([
            $this->addressee->id => "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.",
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();
    }
}
