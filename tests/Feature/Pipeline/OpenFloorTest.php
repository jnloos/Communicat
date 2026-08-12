<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Support\OpenPairRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The open-pair registry: who still owes the round a reply.
 *
 * Replaces the old single-turn floor helper, which read only the very last
 * message — so an obligation to answer evaporated as soon as anything was said
 * in between, and the addressed expert was never asked again.
 */
class OpenFloorTest extends TestCase
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
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
    }

    private function addressedMessage(string $pairType, ?Expert $from = null, ?Expert $to = null): Message
    {
        $message = new Message;
        $message->project_id = $this->project->id;
        $message->expert_id = ($from ?? $this->expert1)->id;
        $message->content = 'Was hältst du davon, Bob?';
        $message->adjacency_pair_type = $pairType;
        $message->adjacency_partner_type = Expert::class;
        $message->adjacency_partner_id = ($to ?? $this->expert2)->id;
        $message->save();

        return $message;
    }

    private function registry(): OpenPairRegistry
    {
        return new OpenPairRegistry($this->project->fresh());
    }

    private function floor(): ?Expert
    {
        $pair = $this->registry()->oldestOpen();

        return $pair === null
            ? null
            : $this->project->contributorMap()->get($pair->adjacency_partner_id);
    }

    public function test_addressed_expert_holds_floor_on_question_pair(): void
    {
        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);

        $this->assertSame($this->expert2->id, $this->floor()?->id);
    }

    public function test_no_floor_on_plain_contribution_pair(): void
    {
        $this->addressedMessage(Message::PAIR_BEITRAG_DISKUSSION);

        $this->assertNull($this->floor());
    }

    public function test_no_floor_without_any_pair(): void
    {
        $this->project->addMessage('Eine Nutzerfrage.', $this->project->owner);

        $this->assertNull($this->floor());
    }

    /**
     * The regression this registry exists for: the obligation used to die the
     * moment anyone else said anything.
     */
    public function test_the_obligation_survives_an_intervening_user_message(): void
    {
        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);
        $this->project->addMessage('Kurz dazwischen: bitte weitermachen.', $this->project->owner);

        $this->assertSame($this->expert2->id, $this->floor()?->id);
    }

    public function test_the_obligation_survives_another_experts_turn(): void
    {
        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);
        $this->project->addMessage('Ein Zwischenbeitrag.', $this->expert1);

        $this->assertSame($this->expert2->id, $this->floor()?->id);
    }

    public function test_an_answered_pair_is_no_longer_open(): void
    {
        $pair = $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);

        $answer = $this->project->addMessage('Halte ich für tragfähig.', $this->expert2);
        $answer->answers_message_id = $pair->id;
        $answer->save();

        $this->assertNull($this->floor());
    }

    public function test_pairs_are_served_oldest_first(): void
    {
        $first = $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT, $this->expert1, $this->expert2);
        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT, $this->expert2, $this->expert1);

        $this->assertSame($first->id, $this->registry()->oldestOpen()?->id);
    }

    public function test_pairs_beyond_the_age_window_are_dropped(): void
    {
        config(['discussion.open_pair_max_age_turns' => 2]);

        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);
        $this->project->addMessage('Beitrag eins.', $this->expert1);
        $this->project->addMessage('Beitrag zwei.', $this->expert1);

        $this->assertNull($this->floor());
    }

    public function test_the_question_is_extracted_for_the_answer_obligation(): void
    {
        $pair = $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);

        $this->assertSame('Was hältst du davon, Bob?', $this->registry()->questionOf($pair));
    }

    /**
     * An address is not a question. Handing the whole contribution over as "the
     * question" made the SPEAK prompt say "X asked you: <entire turn>", and the
     * agent answered by restating it — three near-identical turns in a row.
     */
    public function test_an_address_without_a_question_yields_no_question(): void
    {
        $pair = $this->addressedMessage(Message::PAIR_ANSPRACHE_REAKTION);
        $pair->content = 'Ohne Budget bleibt das Wunschdenken, Bob.';
        $pair->save();

        $this->assertNull($this->registry()->questionOf($pair));
        $this->assertSame('Ohne Budget bleibt das Wunschdenken, Bob.', $this->registry()->pointOf($pair));
    }

    public function test_a_long_point_is_clipped_at_a_word_boundary(): void
    {
        $pair = $this->addressedMessage(Message::PAIR_ANSPRACHE_REAKTION);
        $pair->content = str_repeat('Kapazitaetsplanung und Betreuungsschluessel ', 12);
        $pair->save();

        $point = $this->registry()->pointOf($pair);

        $this->assertStringEndsWith(' …', $point);
        // No mid-word cut: everything before the ellipsis is a whole word.
        $this->assertStringNotContainsString('Kapazitaetspl ', $point);
        $this->assertLessThanOrEqual(165, mb_strlen($point));
    }

    public function test_a_pair_addressed_to_a_removed_contributor_is_dropped(): void
    {
        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);
        $this->project->removeContributingExpert($this->expert2);

        $this->assertNull($this->floor());
    }
}
