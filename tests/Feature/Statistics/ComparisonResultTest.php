<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\SeriesColor;
use App\Discussion\Values\SeriesDefinition;
use App\Livewire\Statistics\ComparisonResult;
use App\Livewire\Statistics\SeriesLabels;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The result below the editor's form, assembled without Livewire. */
class ComparisonResultTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // One run of four turns: Ada speaks three of them.
        $this->user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline']);
        $first = Expert::factory()->create(['name' => 'Ada']);
        $second = Expert::factory()->create(['name' => 'Bo']);
        $project->addContributingExpert($first);
        $project->addContributingExpert($second);

        foreach ([$first, $first, $second, $first] as $index => $speaker) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $project->id, 'status' => 'success',
                'started_at' => now(), 'turn_index' => $index + 1, 'expert_id' => $speaker->id, 'words' => 10,
            ]);
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function series(array $overrides = []): SeriesDefinition
    {
        return SeriesDefinition::fromArray($overrides + ['label' => 'Ada', 'projects' => ['pipeline' => 'ScorePipeline'], 'reference' => ['type' => 'expert_name', 'name' => 'Ada']]);
    }

    /** @param  list<SeriesDefinition>  $series */
    private function compute(array $series, array $view = []): ComparisonResult
    {
        return ComparisonResult::compute($this->user, $series, ComparisonView::fromArray($view + ['metric' => 'turn_share', 'display' => 'table']), [], []);
    }

    public function test_an_empty_label_falls_back_to_the_default_name(): void
    {
        $result = $this->compute([$this->series(['label' => ''])]);

        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $result->results[0]->label);
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $result->lines[0]['label']);
    }

    public function test_an_empty_label_never_repeats_a_default_name_in_use(): void
    {
        $taken = __('comparisons.series.default_label', ['n' => 1]);

        $result = $this->compute([$this->series(['label' => $taken]), $this->series(['label' => ''])]);

        $this->assertSame([$taken, __('comparisons.series.default_label', ['n' => 2])], array_column($result->lines, 'label'));
        $this->assertSame(__('comparisons.series.default_label', ['n' => 3]), SeriesLabels::next($result->series));
    }

    public function test_the_lines_of_one_series_share_its_colour_and_the_curve_tells_them_apart(): void
    {
        $result = $this->compute([$this->series(['reference' => ['type' => 'each']])]);

        $this->assertSame(['indigo-500', 'indigo-500'], array_map('strval', array_column($result->lines, 'color')));
        $this->assertNotSame((string) $result->lines[0]['lineColor'], (string) $result->lines[1]['lineColor']);
    }

    public function test_every_line_of_each_is_named_by_its_agent_never_by_a_seat(): void
    {
        $result = $this->compute([$this->series(['label' => 'Alle', 'reference' => ['type' => 'each']])]);

        $this->assertSame(['Ada', 'Bo'], array_column($result->lines, 'short'));
        $this->assertSame([
            __('comparisons.line_with_name', ['label' => 'Alle', 'name' => 'Ada']),
            __('comparisons.line_with_name', ['label' => 'Alle', 'name' => 'Bo']),
        ], array_column($result->lines, 'label'));
    }

    public function test_agents_without_a_seat_are_named_the_same_way(): void
    {
        // Contributors from before seats existed carry none.
        DB::table('project_contributors')->update(['seat' => null]);

        $result = $this->compute([$this->series(['reference' => ['type' => 'each']])]);

        $this->assertEqualsCanonicalizing(['Ada', 'Bo'], array_column($result->lines, 'short'));
    }

    public function test_more_series_than_colours_repeat_the_palette(): void
    {
        $this->assertFalse($this->compute([$this->series()])->paletteRepeats());

        $this->assertTrue($this->compute(array_fill(0, count(SeriesColor::HUES) + 1, $this->series()))->paletteRepeats());
    }

    public function test_only_the_shown_display_is_prepared(): void
    {
        $bars = $this->compute([$this->series()], ['display' => 'bars'])->chartData();

        $this->assertNotNull($bars['barGroups']);
        $this->assertNull($bars['pies']);
        $this->assertNull($bars['trajectoryRows']);
    }
}
