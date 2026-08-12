<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Services\PromptingPipeline\Support\MentionResolver;
use App\Services\PromptingPipeline\Support\SpeakTrailerResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SpeakTrailerResolverTest extends TestCase
{
    private SpeakTrailerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SpeakTrailerResolver(new MentionResolver);
    }

    /** @return Collection<int, Expert> */
    private function peers(array $names = [35 => 'Verena Albrecht', 43 => 'Beate Sommerfeld']): Collection
    {
        return collect($names)->map(function (string $name, int $id) {
            $expert = new Expert(['name' => $name]);
            $expert->id = $id;

            return $expert;
        })->values();
    }

    private function speakResult(string $content, ?string $token = null, ?string $pairType = null): array
    {
        return [
            'content' => $content,
            'adjacency_partner_token' => $token,
            'adjacency_pair_type' => $pairType,
        ];
    }

    public function test_a_valid_trailer_is_left_alone(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Wie siehst du das?', 'E43', Message::PAIR_FRAGE_ANTWORT),
            $this->peers(),
        );

        $this->assertSame('E43', $result['adjacency_partner_token']);
        $this->assertSame(SpeakTrailerResolver::SOURCE_TRAILER, $result['resolver_source']);
    }

    /**
     * The observed failure (log 717): the text asks Verena a direct question,
     * the trailer says "none", so nobody was ever on the hook to answer.
     */
    public function test_addressee_is_recovered_when_the_trailer_says_none(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Dazu brauche ich eine Planlinie. Verena Albrecht, hast du hier eine klare HR-Planlinie?'),
            $this->peers(),
        );

        $this->assertSame('E35', $result['adjacency_partner_token']);
        $this->assertSame(SpeakTrailerResolver::SOURCE_PROSE, $result['resolver_source']);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $result['adjacency_pair_type']);
    }

    public function test_first_name_alone_is_enough_when_unambiguous(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Welche Quote hältst du für belastbar, Beate?'),
            $this->peers(),
        );

        $this->assertSame('E43', $result['adjacency_partner_token']);
    }

    /**
     * Two names in one question is genuinely ambiguous. Guessing would create
     * an adjacency pair the text does not support and corrupt the closure-rate
     * statistics this work exists to make trustworthy.
     */
    public function test_two_names_in_the_question_stay_unresolved(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Verena Albrecht und Beate Sommerfeld, wie seht ihr das?'),
            $this->peers(),
        );

        $this->assertNull($result['adjacency_partner_token']);
        $this->assertSame(SpeakTrailerResolver::SOURCE_NONE, $result['resolver_source']);
    }

    /**
     * A name mentioned in passing is not a question to that person. Inventing
     * an obligation to reply here would be worse than leaving it a plenum turn.
     */
    public function test_a_name_without_a_question_is_not_an_addressee(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Beate Sommerfeld hat den Punkt bereits gemacht.'),
            $this->peers(),
        );

        $this->assertNull($result['adjacency_partner_token']);
        $this->assertSame(Message::PAIR_BEITRAG_DISKUSSION, $result['adjacency_pair_type']);
    }

    public function test_the_closing_question_wins_over_an_earlier_one(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Reicht das, Verena Albrecht? Beate Sommerfeld, welches Modul zuerst?'),
            $this->peers(),
        );

        $this->assertSame('E43', $result['adjacency_partner_token']);
    }

    public function test_a_missing_trailer_is_reported_and_the_pair_type_derived(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Beate Sommerfeld, wie planst du das?'),
            $this->peers(),
        );

        $this->assertFalse($result['trailer_present']);
        $this->assertTrue($result['ends_with_question']);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $result['adjacency_pair_type']);
    }

    public function test_the_speaker_never_addresses_themselves(): void
    {
        // Verena is not in the peer list — she is the one speaking.
        $result = $this->resolver->reconcile(
            $this->speakResult('Verena Albrecht sieht das anders. Wie steht ihr dazu?'),
            $this->peers([43 => 'Beate Sommerfeld']),
        );

        $this->assertNull($result['adjacency_partner_token']);
    }

    public function test_a_question_naming_nobody_stays_plenum(): void
    {
        $result = $this->resolver->reconcile(
            $this->speakResult('Wie wollen wir damit umgehen?'),
            $this->peers(),
        );

        $this->assertNull($result['adjacency_partner_token']);
        $this->assertTrue($result['ends_with_question']);
    }
}
