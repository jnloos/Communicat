<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\SelectionRecord;
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
}
