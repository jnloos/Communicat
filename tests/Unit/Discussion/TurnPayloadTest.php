<?php

namespace Tests\Unit\Discussion;

use App\Discussion\MissingPayloadSlot;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_a_missing_selection_names_the_fix(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);

        $this->expectException(MissingPayloadSlot::class);
        $this->expectExceptionMessage('SelectSpeaker');

        $payload->selection();
    }

    public function test_reading_a_missing_contribution_names_the_fix(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);

        $this->expectException(MissingPayloadSlot::class);
        $this->expectExceptionMessage('Speak');

        $payload->contribution();
    }

    public function test_carries_thoughts_and_selection(): void
    {
        $expert = Expert::factory()->create();
        $payload = new TurnPayload(Project::factory()->create(), 3, 42);

        $payload->addThought(new Thought($expert->id, 'Ich will widersprechen.', priority: 4));
        $payload->select(new Selection($expert, 'RoundRobinSelector', ['seat' => 1]));

        $this->assertSame(3, $payload->turnIndex);
        $this->assertSame(42, $payload->jobLogId);
        $this->assertSame(4, $payload->thoughtOf($expert)->priority);
        $this->assertTrue($payload->hasSelection());
        $this->assertSame([
            'selector' => 'RoundRobinSelector',
            'speaker_id' => $expert->id,
            'signals' => ['seat' => 1],
            'reasoning' => '',
            'tie_broken' => false,
        ], $payload->selection()->toArray());
    }

    public function test_halt_sets_stop_and_reason(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);

        $payload->halt('turn_budget');

        $this->assertTrue($payload->stop);
        $this->assertSame('turn_budget', $payload->reason);
    }
}
