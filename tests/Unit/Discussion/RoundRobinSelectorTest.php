<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\RoundRobinSelector;
use App\Discussion\TurnPayload;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundRobinSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var Expert[] */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    private function nextSpeakerId(): int
    {
        return (new RoundRobinSelector)->select(new TurnPayload($this->project, 1))->speaker->id;
    }

    public function test_starts_with_the_first_seat(): void
    {
        $this->assertSame($this->experts[0]->id, $this->nextSpeakerId());
    }

    public function test_moves_to_the_seat_after_the_last_speaker_and_wraps_around(): void
    {
        $this->project->addMessage('eins', $this->experts[0]);
        $this->assertSame($this->experts[1]->id, $this->nextSpeakerId());

        $this->project->addMessage('zwei', $this->experts[2]);
        $this->assertSame($this->experts[0]->id, $this->nextSpeakerId());
    }

    public function test_user_messages_do_not_shift_the_rotation(): void
    {
        $this->project->addMessage('eins', $this->experts[0]);
        $this->project->addMessage('Zwischenruf', User::factory()->create());

        $this->assertSame($this->experts[1]->id, $this->nextSpeakerId());
    }

    public function test_records_its_name_and_the_chosen_seat(): void
    {
        $selection = (new RoundRobinSelector)->select(new TurnPayload($this->project, 1));

        $this->assertSame('RoundRobinSelector', $selection->selector);
        $this->assertSame(['seat' => 1], $selection->signals);
        $this->assertFalse($selection->tieBroken);
    }
}
