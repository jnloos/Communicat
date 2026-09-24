<?php

namespace Tests\Feature\Jobs;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\GenerationLoop;
use App\Discussion\TurnRunner;
use App\Events\GenerationStopped;
use App\Events\JobLogged;
use App\Events\MessageGenerated;
use App\Events\PipelineStageChanged;
use App\Jobs\MessageGenerator;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class MessageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private GenerationLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([PipelineStageChanged::class, JobLogged::class, MessageGenerated::class, GenerationStopped::class]);

        FakeAgents::always(ThinkAgent::class, 'GEDANKE: Ich will antworten.');
        FakeAgents::always(SpeakAgent::class, "Alice antwortet kurz.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");
        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung.');

        $this->project = Project::factory()->create();
        $this->project->addContributingExpert(Expert::factory()->create(['name' => 'Alice']));

        $this->loop = app(GenerationLoop::class);
    }

    private function runJob(): void
    {
        (new MessageGenerator($this->project->id))->handle($this->loop, app(TurnRunner::class));
    }

    public function test_does_nothing_unless_the_loop_is_switched_on(): void
    {
        $this->runJob();

        $this->assertSame(0, Message::whereNotNull('expert_id')->count());
        Queue::assertNothingPushed();
    }

    public function test_generates_a_turn_and_queues_the_next_one_with_a_reading_pause(): void
    {
        $this->loop->start($this->project->id);
        $this->loop->markViewing($this->project->id);

        $this->runJob();

        $this->assertSame(1, Message::whereNotNull('expert_id')->count());
        Event::assertDispatched(MessageGenerated::class);
        Queue::assertPushed(MessageGenerator::class, fn (MessageGenerator $job) => $job->projectId === $this->project->id && $job->delay !== null);
    }

    public function test_halts_when_nobody_is_watching(): void
    {
        $this->loop->start($this->project->id);

        $this->runJob();

        $this->assertSame(1, Message::whereNotNull('expert_id')->count());
        $this->assertFalse($this->loop->isGenerating($this->project->id));
        Event::assertDispatched(GenerationStopped::class);
        Queue::assertNothingPushed();
    }

    public function test_a_failed_turn_stops_the_loop(): void
    {
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('kaputt'));
        $this->loop->start($this->project->id);
        $this->loop->markViewing($this->project->id);

        $this->runJob();

        $this->assertFalse($this->loop->isGenerating($this->project->id));
        Event::assertDispatched(GenerationStopped::class);
        Queue::assertNothingPushed();
    }
}
