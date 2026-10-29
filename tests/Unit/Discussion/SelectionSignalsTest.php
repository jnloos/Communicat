<?php

namespace Tests\Unit\Discussion;

use App\Discussion\LateSelectionSignals;
use App\Discussion\Selectors\SpeakerSelector;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The bag a stage before the selector fills for job_logs.selection: measurement
 * data must never be dropped silently, whichever way it goes wrong.
 */
class SelectionSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function selection(array $signals = ['token' => 'E1']): Selection
    {
        return new Selection(speaker: Expert::factory()->create(), selector: 'SomeSelector', signals: $signals, reasoning: 'why', tieBroken: true);
    }

    public function test_extra_signals_are_added_and_everything_else_is_carried_over(): void
    {
        $selection = $this->selection();

        $merged = $selection->withSignals(['jev_kind' => 'bid']);

        $this->assertSame(['token' => 'E1', 'jev_kind' => 'bid'], $merged->signals);
        $this->assertSame($selection->speaker, $merged->speaker);
        $this->assertSame('SomeSelector', $merged->selector);
        $this->assertSame('why', $merged->reasoning);
        $this->assertTrue($merged->tieBroken);
        $this->assertSame($selection, $selection->withSignals([]), 'nothing to add: the selection itself');
    }

    public function test_a_key_both_the_selector_and_an_earlier_stage_wrote_is_a_bug_not_a_precedence_rule(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('token');

        $this->selection()->withSignals(['token' => 'E9']);
    }

    public function test_two_stages_may_not_write_the_same_signal(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);
        $payload->addSelectionSignals(['jev_kind' => 'bid']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('jev_kind');

        $payload->addSelectionSignals(['jev_kind' => 'pick', 'other' => 1]);
    }

    public function test_signals_added_after_the_speaker_is_selected_would_be_lost_so_they_throw(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);
        $payload->select($this->selection());

        $this->expectException(LateSelectionSignals::class);

        $payload->addSelectionSignals(['jev_kind' => 'bid']);
    }

    public function test_select_speaker_merges_the_bag_and_a_clash_fails_the_turn_loudly(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);
        $payload->addSelectionSignals(['jev_kind' => 'bid']);
        $selector = new class($this->selection(['jev_kind' => 'pick'])) implements SpeakerSelector
        {
            public function __construct(private readonly Selection $selection) {}

            public function select(TurnPayload $payload): Selection
            {
                return $this->selection;
            }
        };
        app()->instance('test.selector', $selector);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('jev_kind');

        app(SelectSpeaker::class)->handle($payload, fn (TurnPayload $payload) => $payload, 'test.selector');
    }

    public function test_a_halted_turn_is_not_passed_on_after_the_selection(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);
        $payload->halt('because');
        $selector = new class($this->selection()) implements SpeakerSelector
        {
            public function __construct(private readonly Selection $selection) {}

            public function select(TurnPayload $payload): Selection
            {
                return $this->selection;
            }
        };
        app()->instance('test.selector', $selector);
        $reachedNext = false;

        app(SelectSpeaker::class)->handle($payload, function (TurnPayload $payload) use (&$reachedNext) {
            $reachedNext = true;

            return $payload;
        }, 'test.selector');

        $this->assertFalse($reachedNext);
        $this->assertTrue($payload->hasSelection(), 'the choice is on record');
    }
}
