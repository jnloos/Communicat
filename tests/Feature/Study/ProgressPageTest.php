<?php

namespace Tests\Feature\Study;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use App\Study\Livewire\ProgressPage;
use App\Study\Planning\WavePlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Study\Planning\ManifestTest;
use Tests\TestCase;

class ProgressPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, TagCategory> */
    private array $categories = [];

    public function test_only_admins_see_the_page_and_its_sidebar_link(): void
    {
        $this->actingAs(User::factory()->create())->get('/study')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/study')
            ->assertOk()
            ->assertSee(__('study::progress.heading'))
            ->assertSee(route('study.progress'));
    }

    public function test_without_the_tag_categories_it_says_which_are_missing(): void
    {
        $this->asAdmin();

        Livewire::test(ProgressPage::class)->assertSee('focus profile, duo profile');
    }

    public function test_the_grid_counts_finished_runs_per_pipeline_and_cell(): void
    {
        $this->asAdmin();
        $high = $this->value('focus profile', 'S+G+E+');
        $low = $this->value('duo profile', 'S−G−E−');

        $this->cell('ScorePipeline', $high, $low, spoken: 2);
        $this->cell('ScorePipeline', $high, $low, spoken: 1);
        $this->cell('OrchestratorPipeline', $high, $low, spoken: 0);
        Project::factory()->create(['turn_budget' => 2]); // no focus tag: not a study cell

        $report = Livewire::test(ProgressPage::class)->viewData('report');

        $this->assertSame(['total' => 3, 'done' => 1, 'started' => 1, 'open' => 1, 'given_up' => 0], $report['totals']);
        $this->assertSame(['total' => 2, 'done' => 1, 'started' => 1, 'open' => 0, 'given_up' => 0], $report['grids']['ScorePipeline'][$high->id][$low->id]);
        $this->assertSame(3, $report['remaining']);
        $this->assertSame(3, $report['turnsPerHour']);
        $this->assertFalse($report['stale']);
    }

    /** "All waves" sends an empty string; it must read as no filter, not break the page. */
    public function test_the_wave_filter_narrows_the_grid_and_clears_again(): void
    {
        $this->asAdmin();
        $focus = $this->value('focus profile', 'S+G+E+');
        $duo = $this->value('duo profile', 'S+G+E+');
        $first = $this->value('wave', '1');
        $this->value('wave', '2');
        $this->cell('ScorePipeline', $focus, $duo, spoken: 0)->setTag($this->categories['wave'], $first);
        $this->cell('ScorePipeline', $focus, $duo, spoken: 0);

        $page = Livewire::test(ProgressPage::class)->set('wave', $first->id);
        $this->assertSame(1, $page->viewData('report')['totals']['total']);

        $page->set('wave', '');
        $this->assertNull($page->get('wave'));
        $this->assertSame(2, $page->viewData('report')['totals']['total']);
    }

    public function test_a_given_up_run_and_a_standstill_show(): void
    {
        $this->asAdmin();
        $project = $this->cell('ScorePipeline', $this->value('focus profile', 'S+G+E+'), $this->value('duo profile', 'S+G+E+'), spoken: 1);
        JobLog::query()->update(['finished_at' => now()->subHour()]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'x', 'connection' => 'study', 'queue' => 'study', 'failed_at' => now(),
            'payload' => '{"data":{"command":"O:28:\"App\\\\Study\\\\Jobs\\\\RunStudyProject\":1:{s:9:\"projectId\";i:'.$project->id.';}"}}',
            'exception' => "RuntimeException: Project {$project->id} stopped: failed\n#0 trace",
        ]);
        $this->cell('ScorePipeline', $this->value('focus profile', 'S+G+E+'), $this->value('duo profile', 'S+G+E+'), spoken: 0);

        $report = Livewire::test(ProgressPage::class)->assertSee(__('study::progress.stale_heading'))->viewData('report');

        $this->assertSame(1, $report['totals']['given_up']);
        $this->assertSame($project->id, $report['failedJobs'][0]['project']);
    }

    public function test_an_applied_wave_shows_in_the_grid_and_the_wave_filter(): void
    {
        $this->asAdmin();
        $planning = app(WavePlanning::class);
        $planning->apply($planning->record($planning->prepare(ManifestTest::fixture('tiny-manifest.json'), 1, 1, 1, null), auth()->user()));

        $report = Livewire::test(ProgressPage::class)->viewData('report');

        $this->assertSame(['1'], array_values($report['waves']));
        $this->assertSame(['S+G+E+', 'S−G+E+', 'S+G+E−', 'S−G+E−', 'S+G−E+', 'S−G−E+', 'S+G−E−', 'S−G−E−'], array_values($report['rows']));
        $this->assertSame(64, $report['totals']['open']);
        // 16 blocks × 4 status cells: each of the 64 focus × duo cells holds one run.
        $this->assertSame(64, collect($report['grids']['ScorePipeline'])->flatten(1)->count());

        $filtered = Livewire::test(ProgressPage::class)->set('wave', array_key_first($report['waves']))->viewData('report');
        $this->assertSame(64, $filtered['totals']['total']);
    }

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function value(string $category, string $name): TagValue
    {
        $this->categories[$category] ??= TagCategory::create(['scope' => TagScope::Project, 'name' => $category]);

        return $this->categories[$category]->values()->firstOrCreate(['name' => $name]);
    }

    private function cell(string $pipeline, TagValue $focus, TagValue $duo, int $spoken): Project
    {
        $project = Project::factory()->create(['pipeline' => $pipeline, 'turn_budget' => 2]);
        $project->setTag($this->categories['focus profile'], $focus);
        $project->setTag($this->categories['duo profile'], $duo);
        $expert = Expert::factory()->create();

        for ($turn = 1; $turn <= $spoken; $turn++) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $project->id, 'status' => 'success',
                'started_at' => now(), 'finished_at' => now(), 'turn_index' => $turn, 'expert_id' => $expert->id, 'words' => 10,
            ]);
        }

        return $project;
    }
}
