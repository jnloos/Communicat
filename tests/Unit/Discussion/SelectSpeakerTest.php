<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\RoundRobinSelector;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class SelectSpeakerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_through_laravels_pipeline_with_the_selector_as_a_pipe_parameter(): void
    {
        Event::fake([PipelineStageChanged::class]);

        $project = Project::factory()->create();
        $expert = Expert::factory()->create();
        $project->addContributingExpert($expert);

        $payload = app(Pipeline::class)
            ->send(new TurnPayload($project, 1))
            ->through([SelectSpeaker::with(RoundRobinSelector::class)])
            ->thenReturn();

        $this->assertSame($expert->id, $payload->selection()->speaker->id);
        Event::assertDispatched(PipelineStageChanged::class, fn (PipelineStageChanged $e) => $e->stage === 'routing');
    }

    public function test_rejects_a_class_that_is_not_a_selector(): void
    {
        Event::fake([PipelineStageChanged::class]);

        $this->expectException(InvalidArgumentException::class);

        app(Pipeline::class)
            ->send(new TurnPayload(Project::factory()->create(), 1))
            ->through([SelectSpeaker::with(Project::class)])
            ->thenReturn();
    }
}
