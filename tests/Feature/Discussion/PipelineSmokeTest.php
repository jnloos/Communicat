<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

/**
 * Every pipeline the registry finds must survive two turns. A new pipeline is
 * covered the moment its class exists, as long as it prompts one of the five
 * agents fakeAnswers() covers; a new agent or a different answer goes in there.
 */
class PipelineSmokeTest extends TestCase
{
    use RefreshDatabase;

    public static function pipelineNames(): array
    {
        $names = [];

        foreach (glob(dirname(__DIR__, 3).'/app/Discussion/Pipelines/*Pipeline.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if ($name !== 'TurnPipeline' && $name !== 'UnknownPipeline') {
                $names[$name] = [$name];
            }
        }

        return $names;
    }

    private function fakeAnswers(): void
    {
        // One answer per agent class, carrying every field any stage reads: each
        // think variant takes its own fields out of it, so this covers the
        // speaker, the bidding and the proposing stage alike. A field a stage
        // does not read is simply ignored.
        FakeAgents::always(ThinkAgent::class, [
            'thought' => 'Ich will etwas beitragen.',
            'priority' => 3,
            'draft' => 'Ein kurzer Entwurf.',
        ]);
        FakeAgents::always(SpeakAgent::class, [
            'contribution' => 'Ein kurzer Beitrag.',
            'addressee' => null,
        ]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);

        // A token that need not exist: the selector then falls back to the tie
        // breaker and records it, which is a pass for a smoke test either way.
        FakeAgents::always(SelectorAgent::class, [
            'speaker' => 'E1',
            'reasoning' => 'Weil E1 noch nicht dran war.',
        ]);
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [['expert' => 'E1', 'score' => 7]],
            'reasoning' => 'Der Entwurf ist konkret.',
        ]);
    }

    #[DataProvider('pipelineNames')]
    public function test_pipeline_runs_two_turns(string $name): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeAnswers();

        $this->assertTrue(app(PipelineRegistry::class)->has($name), "The registry does not find [{$name}].");

        $project = Project::factory()->create(['pipeline' => $name]);
        foreach (Expert::factory()->count(3)->create() as $expert) {
            $project->addContributingExpert($expert);
        }

        app(TurnRunner::class)->run($project);
        app(TurnRunner::class)->run($project);

        $this->assertSame(2, Message::where('project_id', $project->id)->whereNotNull('expert_id')->count());
        $this->assertSame(
            ['success', 'success'],
            JobLog::where('project_id', $project->id)->orderBy('id')->pluck('status')->all(),
        );
        $this->assertGreaterThan(0, JobLog::where('project_id', $project->id)->min('words'));
    }
}
