<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Data\Directive;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How the open pair is presented to the answering persona.
 *
 * The first version of this block quoted the asker's entire contribution under
 * the label "X hat dich gefragt" whenever the pair carried no question mark.
 * The agent duly "answered" by restating it, producing three near-identical
 * turns in a row (project 32, messages 368-370).
 */
class AnswerObligationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $asker;

    private Expert $addressee;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Eine ausreichend lange Beschreibung für den Test.',
            'settings' => [], 'user_id' => $user->id,
        ]));
        $this->asker = Expert::factory()->create(['name' => 'Sophie Wagner']);
        $this->addressee = Expert::factory()->create(['name' => 'Jan Lehmann']);
        $this->project->addContributingExpert($this->asker);
        $this->project->addContributingExpert($this->addressee);
    }

    private function pair(string $content, string $pairType): Message
    {
        $message = $this->project->addMessage($content, $this->asker);
        $message->adjacency_pair_type = $pairType;
        $message->adjacency_partner_type = Expert::class;
        $message->adjacency_partner_id = $this->addressee->id;
        $message->save();

        return $message;
    }

    private function prompt(?Message $openPair): string
    {
        return (new PromptBuilder)->speak(
            $this->project,
            $this->addressee,
            ['memory' => 'x', 'beitragsabsicht' => 'y'],
            new Directive(
                role: 'frage_beantworten',
                agendaStep: 'divergenz',
                convergenceIntent: '',
                handBackToUser: false,
            ),
            $openPair,
        );
    }

    public function test_a_real_question_is_quoted_as_a_question(): void
    {
        $pair = $this->pair('Ohne Zahlen trägt das nicht. Welche Fehlerrate hältst du für vertretbar?', Message::PAIR_FRAGE_ANTWORT);

        $prompt = $this->prompt($pair);

        $this->assertStringContainsString('ANTWORTPFLICHT', $prompt);
        $this->assertStringContainsString('Die Frage an dich lautet: "Welche Fehlerrate hältst du für vertretbar?"', $prompt);
        // Only the question, not the whole contribution.
        $this->assertStringNotContainsString('Die Frage an dich lautet: "Ohne Zahlen', $prompt);
    }

    public function test_an_address_is_not_presented_as_a_question(): void
    {
        $pair = $this->pair('Ohne Budget bleibt das Wunschdenken, Jan.', Message::PAIR_ANSPRACHE_REAKTION);

        $prompt = $this->prompt($pair);

        $this->assertStringContainsString('ohne eine Frage zu stellen', $prompt);
        $this->assertStringContainsString('Erfinde keine Frage, die nicht gestellt wurde.', $prompt);
        $this->assertStringNotContainsString('Die Frage an dich lautet', $prompt);
    }

    public function test_the_quote_is_marked_as_orientation_only(): void
    {
        $pair = $this->pair('Welche Fehlerrate hältst du für vertretbar?', Message::PAIR_FRAGE_ANTWORT);

        $this->assertStringContainsString('NUR ORIENTIERUNG', $this->prompt($pair));
    }

    public function test_no_obligation_block_without_an_open_pair(): void
    {
        $this->assertStringNotContainsString('ANTWORTPFLICHT', $this->prompt(null));
    }
}
