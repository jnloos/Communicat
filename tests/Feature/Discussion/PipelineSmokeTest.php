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
        // One answer per agent class, carrying every marker any stage parses: each
        // think variant reads its own section out of it, so this covers the speaker,
        // the bidding and the proposing stage alike.
        FakeAgents::always(ThinkAgent::class, implode('
', [
            'GEDANKE: Ich will etwas beitragen.',
            'PRIORITÄT: 3',
            'ENTWURF: Ein kurzer Entwurf.',
        ]));
        FakeAgents::always(SpeakAgent::class, 'Ein kurzer Beitrag.
---STEUERUNG---
ADRESSAT: none');
        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung.');
        // A token that need not exist: the selector then falls back to the tie
        // breaker and records it, which is a pass for a smoke test either way.
        FakeAgents::always(SelectorAgent::class, 'SPRECHER: E1
BEGRÜNDUNG: Weil E1 noch nicht dran war.');
        FakeAgents::always(JudgeAgent::class, 'BEWERTUNG: E1 7
BEGRÜNDUNG: Der Entwurf ist konkret.');
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
