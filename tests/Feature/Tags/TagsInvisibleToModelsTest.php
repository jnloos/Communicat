<?php

namespace Tests\Feature\Tags;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeAgents;
use Tests\Feature\Discussion\PipelineSmokeTest;
use Tests\TestCase;

/**
 * Tags are the researcher's description of a run, never a condition of it. If
 * a category or value name ever reached a prompt, a model would see its own
 * experimental label — so every pipeline runs here with tagged personas and a
 * tagged project, and no marker may turn up in what the models were sent.
 */
class TagsInvisibleToModelsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKERS = ['ZZKATPERSONA', 'ZZWERTPERSONA', 'ZZKATPROJEKT', 'ZZWERTPROJEKT'];

    public static function pipelineNames(): array
    {
        return PipelineSmokeTest::pipelineNames();
    }

    private function fakeAnswers(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ich will etwas beitragen.', 'priority' => 3, 'draft' => 'Ein kurzer Entwurf.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein kurzer Beitrag.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);
        FakeAgents::always(SelectorAgent::class, ['speaker' => 'E1', 'reasoning' => 'Weil.']);
        FakeAgents::always(JudgeAgent::class, ['speaker' => 'E1', 'label' => 'A', 'reasoning' => 'Weil.']);
    }

    #[DataProvider('pipelineNames')]
    public function test_no_tag_reaches_a_prompt_or_the_run_config(string $name): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeAnswers();

        $personaCategory = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'ZZKATPERSONA']);
        $personaValue = $personaCategory->values()->create(['name' => 'ZZWERTPERSONA']);
        $projectCategory = TagCategory::create(['scope' => TagScope::Project, 'name' => 'ZZKATPROJEKT']);
        $projectValue = $projectCategory->values()->create(['name' => 'ZZWERTPROJEKT']);

        $project = Project::factory()->create(['pipeline' => $name, 'summarize_threshold' => 2, 'summarize_oldest' => 1]);
        $project->setTag($projectCategory, $projectValue);

        foreach (Expert::factory()->count(3)->create() as $expert) {
            $expert->setTag($personaCategory, $personaValue);
            $project->addContributingExpert($expert);
        }

        foreach (range(1, 3) as $turn) {
            app(TurnRunner::class)->run($project->fresh());
        }

        $prompts = PromptLog::pluck('prompt')->implode("\n");
        $runConfig = (string) json_encode($project->fresh()->run_config);

        $this->assertNotSame('', $prompts);
        $this->assertTrue(PromptLog::where('purpose', 'summarize')->exists(), 'The summarize prompt never ran, so this test proves less than it claims.');

        foreach (self::MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $prompts);
            $this->assertStringNotContainsString($marker, $runConfig);
        }
    }
}
