<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SpeakerShare;
use Tests\TestCase;

class MetricTest extends TestCase
{
    private function speaker(int $turns, int $words, float $turnShare, float $wordShare): SpeakerShare
    {
        return new SpeakerShare(expertId: random_int(1, 1_000_000), name: 'X', seat: 1, turns: $turns, words: $words, turnShare: $turnShare, wordShare: $wordShare);
    }

    public function test_a_group_counts_together_or_per_head(): void
    {
        $group = [$this->speaker(1, 10, 0.25, 0.1), $this->speaker(2, 30, 0.5, 0.3)];

        $this->assertSame(0.75, Metric::TurnShare->ofGroup($group, Aggregate::Together));
        $this->assertSame(0.375, Metric::TurnShare->ofGroup($group, Aggregate::PerHead));
        $this->assertSame(0.2, Metric::WordShare->ofGroup($group, Aggregate::PerHead));
    }

    public function test_words_per_turn_ignores_the_aggregate(): void
    {
        $group = [$this->speaker(1, 10, 0.25, 0.1), $this->speaker(3, 50, 0.5, 0.3)];

        $this->assertSame(15.0, Metric::WordsPerTurn->ofGroup($group, Aggregate::PerHead));
    }

    public function test_the_fair_share_of_a_group(): void
    {
        $this->assertSame(0.75, Metric::TurnShare->fairShare(3, 4, Aggregate::Together));
        $this->assertSame(0.25, Metric::WordShare->fairShare(3, 4, Aggregate::PerHead));
        $this->assertNull(Metric::WordsPerTurn->fairShare(3, 4, Aggregate::Together));
        $this->assertNull(Metric::TurnGini->fairShare(4, 4, Aggregate::Together));
    }

    public function test_a_delta_is_signed_and_in_points_for_shares(): void
    {
        app()->setLocale('en');

        $this->assertSame('+31.7 pp', Metric::TurnShare->formatDelta(0.317));
        $this->assertSame('−5.0 pp', Metric::WordShare->formatDelta(-0.05));
        $this->assertSame('±0.0 pp', Metric::TurnShare->formatDelta(0.0));
        $this->assertSame('+0.12', Metric::TurnGini->formatDelta(0.12));
    }

    public function test_labels_come_from_the_question_texts(): void
    {
        app()->setLocale('en');

        $this->assertSame('Turn share', Metric::TurnShare->label());
    }
}
