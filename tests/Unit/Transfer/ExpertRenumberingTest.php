<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\ExpertRenumbering;
use PHPUnit\Framework\TestCase;

class ExpertRenumberingTest extends TestCase
{
    public function test_a_selection_follows_every_renumbered_id_and_token(): void
    {
        $renumbering = new ExpertRenumbering([1 => 2, 2 => 3]);

        $selection = $renumbering->selection([
            'selector' => 'HighestBidSelector',
            'speaker_id' => 1,
            'reasoning' => 'E1 bid highest',
            'signals' => ['bids' => ['E1' => 5, 'E2' => 1, 'E9' => 3], 'fallbacks' => ['E2', 'E9'], 'token' => 'E1', 'order' => ['A' => 1, 'B' => '2', 'C' => 9]],
        ]);

        $this->assertSame(2, $selection['speaker_id']);
        $this->assertSame(['E2' => 5, 'E3' => 1, 'E9' => 3], $selection['signals']['bids']);
        $this->assertSame(['E3', 'E9'], $selection['signals']['fallbacks']);
        $this->assertSame('E2', $selection['signals']['token']);
        $this->assertSame(['A' => 2, 'B' => 3, 'C' => 9], $selection['signals']['order']);
        $this->assertSame('E1 bid highest', $selection['reasoning']);
    }

    public function test_the_cast_follows_renumbered_ids_and_keeps_its_shape(): void
    {
        $config = ['pipeline' => 'ScorePipeline', 'cast' => [
            ['seat' => 1, 'expert_id' => 1, 'name' => 'Alex', 'role' => 'CEO'],
            ['seat' => 2, 'expert_id' => 9, 'name' => 'Kim', 'role' => 'Lead'],
        ]];

        $renumbered = (new ExpertRenumbering([1 => 4]))->runConfig($config);

        $this->assertSame(['pipeline' => 'ScorePipeline', 'cast' => [
            ['seat' => 1, 'expert_id' => 4, 'name' => 'Alex', 'role' => 'CEO'],
            ['seat' => 2, 'expert_id' => 9, 'name' => 'Kim', 'role' => 'Lead'],
        ]], $renumbered);
    }

    public function test_an_empty_map_or_missing_parts_change_nothing(): void
    {
        $config = ['pipeline' => 'ScorePipeline'];
        $selection = ['selector' => 'RoundRobinSelector', 'speaker_id' => 1];

        $this->assertSame($selection, (new ExpertRenumbering([]))->selection($selection));
        $this->assertSame($config, (new ExpertRenumbering([1 => 2]))->runConfig($config));
        $this->assertNull((new ExpertRenumbering([1 => 2]))->runConfig(null));
        $this->assertNull((new ExpertRenumbering([1 => 2]))->selection(null));
        $this->assertSame(['speaker_id' => 2, 'signals' => 'x'], (new ExpertRenumbering([1 => 2]))->selection(['speaker_id' => 1, 'signals' => 'x']));
    }
}
