<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Jev\JevBidder;
use App\Discussion\Schemas\ThoughtWithPrioritySchema;
use App\Discussion\Values\JevBid;
use App\Discussion\Values\JevChoice;
use App\Discussion\Values\JevScore;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use PHPUnit\Framework\TestCase;

class JevReadingTest extends TestCase
{
    public function test_the_option_with_the_top_probability_leads(): void
    {
        $read = JevChoice::read(new ChoiceAnswer('E2', ['E1' => 0.2, 'E2' => 0.7, 'E3' => 0.1], 0.9), ['E1', 'E2', 'E3']);

        $this->assertSame(['E2'], $read->leaders);
        $this->assertSame(['E1' => 0.2, 'E2' => 0.7, 'E3' => 0.1], $read->probabilities);
        $this->assertSame(0.9, $read->confidence);
    }

    public function test_equal_top_probabilities_are_all_leaders_whatever_choice_names(): void
    {
        $read = JevChoice::read(new ChoiceAnswer('E3', ['E1' => 0.4, 'E2' => 0.4, 'E3' => 0.2]), ['E1', 'E2', 'E3']);

        $this->assertSame(['E1', 'E2'], $read->leaders);
    }

    public function test_a_difference_below_the_sixth_place_is_a_tie(): void
    {
        $read = JevChoice::read(new ChoiceAnswer('E1', ['E1' => 0.4000001, 'E2' => 0.4000002, 'E3' => 0.1999997]), ['E1', 'E2', 'E3']);

        $this->assertSame(['E1', 'E2'], $read->leaders);
    }

    public function test_an_option_jev_did_not_mention_has_probability_zero_and_unknown_options_are_ignored(): void
    {
        $read = JevChoice::read(new ChoiceAnswer('E1', ['E1' => 0.6, 'E9' => 0.4]), ['E1', 'E2']);

        $this->assertSame(['E1' => 0.6, 'E2' => 0.0], $read->probabilities);
        $this->assertSame(['E1'], $read->leaders);
    }

    public function test_without_probabilities_the_named_choice_decides_if_it_was_offered(): void
    {
        $read = JevChoice::read(new ChoiceAnswer('E2', []), ['E1', 'E2']);

        $this->assertSame(['E2'], $read->leaders);
        $this->assertSame([], $read->probabilities);
        $this->assertNull(JevChoice::read(new ChoiceAnswer('E9', []), ['E1', 'E2']));
    }

    public function test_unreadable_choices_are_null(): void
    {
        $this->assertNull(JevChoice::read(null, ['E1', 'E2']));
        $this->assertNull(JevChoice::read(new BooleanAnswer(0.5), ['E1', 'E2']));
        $this->assertNull(JevChoice::read(new ChoiceAnswer('E1', ['E1' => -0.1, 'E2' => 1.1]), ['E1', 'E2']));
        $this->assertNull(JevChoice::read(new ChoiceAnswer('E1', ['E1' => 0.0, 'E2' => 0.0]), ['E1', 'E2']));
        $this->assertNull(JevChoice::read(new ChoiceAnswer('E1', ['E1' => 'viel']), ['E1', 'E2']));
    }

    public function test_the_score_is_the_expected_level_by_position_on_the_one_to_five_scale(): void
    {
        $this->assertSame(5.0, JevScore::read(new ScoreAnswer(4.0, [0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 1.0], []), 5)->value);
        $this->assertSame(1.5, JevScore::read(new ScoreAnswer(0.5, [0 => 0.5, 1 => 0.5, 2 => 0.0, 3 => 0.0, 4 => 0.0], []), 5)->value);
    }

    public function test_the_numbering_of_the_levels_does_not_matter(): void
    {
        $oneBased = JevScore::read(new ScoreAnswer(0.0, [1 => 0.25, 2 => 0.25, 3 => 0.25, 4 => 0.25, 5 => 0.0], []), 5);
        $zeroBased = JevScore::read(new ScoreAnswer(0.0, [0 => 0.25, 1 => 0.25, 2 => 0.25, 3 => 0.25, 4 => 0.0], []), 5);

        $this->assertSame(2.5, $oneBased->value);
        $this->assertSame(2.5, $zeroBased->value);
    }

    public function test_probabilities_that_do_not_sum_to_one_are_normalised(): void
    {
        $this->assertSame(5.0, JevScore::read(new ScoreAnswer(4.0, [0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.5], []), 5)->value);
    }

    public function test_without_probabilities_the_sdk_score_counted_from_zero_is_used(): void
    {
        $this->assertSame(3.5, JevScore::read(new ScoreAnswer(2.5, [], []), 5)->value);
        $this->assertNull(JevScore::read(new ScoreAnswer(7.0, [], []), 5));
    }

    public function test_unreadable_scores_are_null(): void
    {
        $this->assertNull(JevScore::read(null, 5));
        $this->assertNull(JevScore::read(new BooleanAnswer(0.5), 5));
        $this->assertNull(JevScore::read(new ScoreAnswer(1.0, [0 => 0.5, 1 => 0.5], []), 5), 'wrong number of levels');
        $this->assertNull(JevScore::read(new ScoreAnswer(1.0, [0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0], []), 5));
    }

    public function test_a_bid_is_the_expected_level_rounded_to_a_whole_number(): void
    {
        $this->assertSame(2, (new JevBid(1.5, null, null, 1))->level());
        $this->assertSame(2, (new JevBid(2.4, null, null, 1))->level());
        $this->assertSame(5, (new JevBid(5.0, null, null, 1))->level());
        $this->assertNull((new JevBid(null, null, null, 1))->level());
        $this->assertTrue((new JevBid(null, null, null, 1))->unreadable());
        $this->assertFalse((new JevBid(1.0, null, null, 1))->unreadable());
    }

    public function test_the_levels_cover_exactly_the_scale_of_the_bids(): void
    {
        $this->assertCount(ThoughtWithPrioritySchema::HIGHEST - ThoughtWithPrioritySchema::LOWEST + 1, JevBidder::LEVELS);
    }
}
