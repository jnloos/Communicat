<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

/**
 * Every pipeline the registry finds must survive two turns. A new pipeline is
 * covered the moment its class exists; if it needs other fake answers, extend
 * fakeAnswers().
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

    private function fakeAnswers(): FakeLlmClient
    {
        return (new FakeLlmClient)
            ->push('think', 'GEDANKE: Ich will etwas beitragen.')
            ->push('speak', "Ein kurzer Beitrag.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion")
            ->push('summarize', 'Zusammenfassung.');
    }

    #[DataProvider('pipelineNames')]
    public function test_pipeline_runs_two_turns(string $name): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->fakeAnswers()));

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
