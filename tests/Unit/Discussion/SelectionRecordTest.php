<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\BidResolution;
use App\Discussion\Values\JevAgentBid;
use App\Discussion\Values\JevBid;
use App\Discussion\Values\JevPick;
use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;
use PHPUnit\Framework\TestCase;

class SelectionRecordTest extends TestCase
{
    public function test_round_robin_records_neither_a_fallback_nor_a_bid(): void
    {
        $record = SelectionRecord::written('RoundRobinSelector', 3, SelectionRecord::seatSignals(2), '', false);

        $this->assertNull($record->fallback());
        $this->assertSame(['bid' => null, 'imputed' => null], $record->bidOf(3));
        $this->assertSame([
            'selector' => 'RoundRobinSelector',
            'speaker_id' => 3,
            'signals' => ['seat' => 2],
            'reasoning' => '',
            'tie_broken' => false,
        ], $record->toArray());
    }

    public function test_score_falls_back_when_any_bid_was_unreadable_and_flags_an_imputed_bid(): void
    {
        $record = SelectionRecord::written('HighestBidSelector', 1, SelectionRecord::bidSignals(['E1' => 4, 'E2' => 1], ['E2']), '', false);

        $this->assertTrue($record->fallback());
        $this->assertSame(['bid' => 4, 'imputed' => false], $record->bidOf(1));
        $this->assertSame(['bid' => null, 'imputed' => true], $record->bidOf(2));
        $this->assertSame(['bid' => null, 'imputed' => false], $record->bidOf(9));
        $this->assertSame(['bid' => null, 'imputed' => null], $record->bidOf(null));
    }

    public function test_score_with_every_bid_readable_did_not_fall_back(): void
    {
        $record = SelectionRecord::fromArray(['signals' => SelectionRecord::bidSignals(['E1' => 4], [])]);

        $this->assertFalse($record->fallback());
    }

    public function test_orchestrator_and_judge_fall_back_on_an_unreadable_pick(): void
    {
        $picked = SelectionRecord::written('OrchestratorSelector', 1, SelectionRecord::pickSignals('E1', fallback: false), 'why', false);
        $missed = SelectionRecord::written('JudgeSelector', 2, SelectionRecord::pickSignals('E7', fallback: true), 'why', true);

        $this->assertFalse($picked->fallback());
        $this->assertTrue($missed->fallback());
        $this->assertTrue($missed->tieBroken());
        $this->assertSame(['bid' => null, 'imputed' => null], $missed->bidOf(2));
        $this->assertSame(['token' => 'E7', 'fallback' => true], $missed->toArray()['signals']);
    }

    public function test_blind_judge_carries_its_label_and_order(): void
    {
        $record = SelectionRecord::written('BlindJudgeSelector', 5, SelectionRecord::blindPickSignals('B', ['A' => 4, 'B' => 5], fallback: false), '', false);

        $this->assertFalse($record->fallback());
        $this->assertSame('B', $record->label());
        $this->assertSame(['label' => 'B', 'order' => ['A' => 4, 'B' => 5], 'fallback' => false], $record->toArray()['signals']);
    }

    public function test_an_empty_or_missing_selection_reads_as_nothing(): void
    {
        $record = SelectionRecord::fromArray(null);

        $this->assertTrue($record->isEmpty());
        $this->assertSame([], $record->toArray());
        $this->assertNull($record->selector());
        $this->assertNull($record->speakerId());
        $this->assertNull($record->tieBroken());
        $this->assertNull($record->fallback());
        $this->assertNull($record->label());
    }

    public function test_unknown_keys_and_malformed_signals_survive_a_round_trip(): void
    {
        $stored = ['selector' => 'JudgeSelector', 'speaker_id' => 1, 'signals' => 'x', 'scores' => ['E1' => 8]];

        $mapped = SelectionRecord::fromArray($stored)->mapExpertIds(fn (int $id) => $id + 1);

        $this->assertSame(['selector' => 'JudgeSelector', 'speaker_id' => 2, 'signals' => 'x', 'scores' => ['E1' => 8]], $mapped->toArray());
        $this->assertNull(SelectionRecord::fromArray($stored)->fallback());
    }

    public function test_map_expert_ids_renumbers_every_id_and_token_but_keeps_untouched_values_as_they_were(): void
    {
        $record = SelectionRecord::fromArray([
            'speaker_id' => '1',
            'reasoning' => 'E1 bid highest',
            'signals' => ['bids' => ['E1' => 5, 'E9' => 3], 'fallbacks' => ['E9'], 'token' => 'E1', 'order' => ['A' => 1, 'B' => '9', 'C' => 'n/a']],
        ]);

        $mapped = $record->mapExpertIds(fn (int $id) => $id === 1 ? 2 : $id)->toArray();

        $this->assertSame(2, $mapped['speaker_id']);
        $this->assertSame('E1 bid highest', $mapped['reasoning']);
        $this->assertSame(['E2' => 5, 'E9' => 3], $mapped['signals']['bids']);
        $this->assertSame(['E9'], $mapped['signals']['fallbacks']);
        $this->assertSame('E2', $mapped['signals']['token']);
        $this->assertSame(['A' => 2, 'B' => '9', 'C' => 'n/a'], $mapped['signals']['order']);
    }

    private function pick(?string $key, array $probabilities, bool $fallback = false, bool $asked = true, ?float $confidence = 0.8, ?string $model = 'jev-1.13.0', ?int $latencyMs = 120): JevPick
    {
        return new JevPick((new Expert)->forceFill(['id' => 5]), $key, tieBroken: $fallback, fallback: $fallback, asked: $asked, probabilities: $probabilities, confidence: $confidence, model: $model, latencyMs: $latencyMs);
    }

    /** @param  array<string, array{?int, ?float}>  $bids  token => [own bid, jev value] */
    private function bids(array $bids): array
    {
        return array_map(fn (string $token, array $bid) => new JevAgentBid($token, $bid[0], new JevBid($bid[1], $bid[1] === null ? null : 0.7, $bid[1] === null ? null : 'jev-1.13.0', 80)), array_keys($bids), $bids);
    }

    public function test_a_jev_pick_carries_what_jev_said_and_reads_the_speakers_probability(): void
    {
        $signals = SelectionRecord::jevPickSignals($this->pick('E5', ['E4' => 0.25, 'E5' => 0.75]));
        $record = SelectionRecord::written('JevSpeakerSelector', 5, $signals, '', false);

        $this->assertSame([
            'token' => 'E5', 'fallback' => false,
            'jev_kind' => 'pick', 'jev_asked' => true, 'jev_probabilities' => ['E4' => 0.25, 'E5' => 0.75],
            'jev_confidence' => 0.8, 'jev_model' => 'jev-1.13.0', 'jev_latency_ms' => 120,
        ], $signals);
        $this->assertFalse($record->fallback());
        $this->assertSame(0.75, $record->speakerProbability(5));
        $this->assertSame(0.25, $record->speakerProbability(4));
        $this->assertNull($record->speakerProbability(9));
        $this->assertNull($record->speakerProbability(null));
        $this->assertNull($record->speakerJevScore(5), 'a pick has a probability, not a score');
    }

    public function test_a_blind_jev_pick_reads_the_probability_of_the_speakers_letter(): void
    {
        $signals = SelectionRecord::jevBlindPickSignals($this->pick('B', ['A' => 0.3, 'B' => 0.7], confidence: null, latencyMs: 90), ['A' => 4, 'B' => 5]);
        $record = SelectionRecord::written('JevBlindJudgeSelector', 5, $signals, '', false);

        $this->assertSame('B', $record->label());
        $this->assertSame(0.7, $record->speakerProbability(5));
        $this->assertSame(0.3, $record->speakerProbability(4));
    }

    public function test_a_failed_jev_pick_is_a_fallback_without_a_probability_and_counts_as_a_fallen_back_call(): void
    {
        $record = SelectionRecord::written('JevSpeakerSelector', 5, SelectionRecord::jevPickSignals($this->pick(null, [], fallback: true, confidence: null, model: null, latencyMs: 30)), '', true);

        $this->assertTrue($record->fallback());
        $this->assertNull($record->speakerProbability(5));
        $this->assertTrue($record->everyJevCallFellBack());
    }

    public function test_a_pick_jev_was_never_asked_about_has_no_probability_and_is_no_fallen_back_call(): void
    {
        $lone = SelectionRecord::written('JevSpeakerSelector', 5, SelectionRecord::jevPickSignals($this->pick('E5', [], asked: false, confidence: null, model: null, latencyMs: null)), '', false);
        $noDrafts = SelectionRecord::written('JevJudgeSelector', 5, SelectionRecord::jevPickSignals($this->pick(null, [], fallback: true, asked: false, confidence: null, model: null, latencyMs: null)), '', true);

        $this->assertNull($lone->speakerProbability(5));
        $this->assertFalse($lone->everyJevCallFellBack());
        $this->assertTrue($noDrafts->fallback());
        $this->assertFalse($noDrafts->everyJevCallFellBack(), 'nothing was asked, so Jev did not fail');
    }

    public function test_jev_bids_sit_next_to_the_bids_they_became_and_the_speakers_score_is_not_a_probability(): void
    {
        $signals = SelectionRecord::bidSignals(['E1' => 4, 'E2' => 1], ['E2'])
            + SelectionRecord::jevBidSignals($this->bids(['E1' => [5, 3.6], 'E2' => [3, null]]), BidResolution::Rounded);
        $record = SelectionRecord::written('HighestBidSelector', 1, $signals, '', false);

        $this->assertTrue($record->fallback(), 'a Jev bid was unreadable');
        $this->assertSame(['bid' => 4, 'imputed' => false], $record->bidOf(1));
        $this->assertSame(3.6, $record->speakerJevScore(1));
        $this->assertNull($record->speakerJevScore(2));
        $this->assertNull($record->speakerProbability(1), 'the unrounded score is its own column');
        $this->assertFalse($record->everyJevCallFellBack(), 'one Jev bid was readable');
        $this->assertSame('bid', $signals['jev_kind']);
        $this->assertSame('rounded', $signals['jev_bid_resolution']);
    }

    public function test_a_turn_in_which_every_jev_bid_was_unreadable_counts_as_fallen_back(): void
    {
        $signals = SelectionRecord::jevBidSignals($this->bids(['E1' => [5, null], 'E2' => [3, null]]), BidResolution::Rounded);

        $this->assertTrue(SelectionRecord::written('HighestBidSelector', 1, $signals, '', false)->everyJevCallFellBack());
        $this->assertFalse(SelectionRecord::fromArray(['signals' => SelectionRecord::bidSignals(['E1' => 4], [])])->everyJevCallFellBack(), 'not a Jev turn');
        $this->assertFalse(SelectionRecord::fromArray(null)->everyJevCallFellBack());
    }

    public function test_the_unrounded_scores_are_read_back_from_the_signals(): void
    {
        $signals = SelectionRecord::jevBidSignals($this->bids(['E1' => [5, 3.6], 'E2' => [3, null]]), BidResolution::Unrounded);

        $this->assertSame(['E1' => 3.6, 'E2' => null], SelectionRecord::jevScoresIn($signals));
        $this->assertSame([], SelectionRecord::jevScoresIn([]));
        $this->assertSame(['bids' => ['E1' => 4], 'fallbacks' => [], 'jev_tie_pool' => 2], SelectionRecord::jevBidPickSignals(['E1' => 4], [], 2));
    }

    public function test_a_renumbering_reaches_every_jev_map(): void
    {
        $record = SelectionRecord::fromArray([
            'speaker_id' => 1,
            'signals' => SelectionRecord::bidSignals(['E1' => 4], [])
                + SelectionRecord::jevBidSignals($this->bids(['E1' => [5, 3.6]]), BidResolution::Rounded)
                + ['jev_probabilities' => ['E1' => 1.0, 'A' => 0.5]],
        ]);

        $signals = $record->mapExpertIds(fn (int $id) => $id + 10)->toArray()['signals'];

        $this->assertSame(['E11' => 5], $signals['jev_own_bids']);
        $this->assertSame(['E11' => 3.6], $signals['jev_scores']);
        $this->assertSame(['E11' => 0.7], $signals['jev_confidences']);
        $this->assertSame(['E11' => 'jev-1.13.0'], $signals['jev_models']);
        $this->assertSame(['E11' => 80], $signals['jev_latencies_ms']);
        $this->assertSame(['E11' => 1.0, 'A' => 0.5], $signals['jev_probabilities']);
    }
}
