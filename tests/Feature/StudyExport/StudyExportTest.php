<?php

namespace Tests\Feature\StudyExport;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\PromptLog;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\StudyExport\StudyExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class StudyExportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, list<array<string, string>>> file => rows keyed by header */
    private function read(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $files = [];

        foreach (['runs.csv', 'participants.csv', 'turns.csv'] as $file) {
            $lines = array_map('str_getcsv', explode("\n", trim($zip->getFromName($file))));
            $header = array_shift($lines);
            $files[$file] = array_map(fn (array $line) => array_combine($header, $line), $lines);
        }

        return $files;
    }

    public function test_three_tables_with_a_column_per_tag_category_and_honest_gaps(): void
    {
        $viewer = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $holder = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $true = $holder->values()->create(['name' => 'true']);
        [$alex, $kim] = Expert::factory()->count(2)->create()->all();
        $alex->setTag($holder, $true);
        $run = Project::factory()->create(['user_id' => $viewer->id, 'pipeline' => 'ScorePipeline', 'title' => 'School']);
        $run->addContributingUser($viewer);
        $run->addContributingExpert($alex);
        $run->addContributingExpert($kim);
        $run->setTag($condition, $status);
        $group = ProjectGroup::create(['user_id' => $viewer->id, 'name' => 'Score', 'position' => 1]);
        $run->fileInGroupFor($viewer, $group->id);
        $spoken = JobLog::create(['job_class' => 'x', 'project_id' => $run->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'expert_id' => $alex->id, 'seat' => 1, 'words' => 10, 'chars' => 50,
            'selection' => ['selector' => 'HighestBidSelector', 'speaker_id' => $alex->id, 'signals' => ['bids' => ["E{$alex->id}" => 5, "E{$kim->id}" => 2], 'fallbacks' => []], 'tie_broken' => false]]);
        PromptLog::create(['job_log_id' => $spoken->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 100, 'tokens_out' => 20, 'tokens_reasoning' => null]);
        JobLog::create(['job_class' => 'x', 'project_id' => $run->id, 'status' => 'failed', 'started_at' => now(), 'turn_index' => 2, 'error' => 'HTTP 500']);
        $path = tempnam(sys_get_temp_dir(), 'study');

        app(StudyExport::class)->write(collect([$run]), $viewer, $path);
        $files = $this->read($path);

        $this->assertSame('status', $files['runs.csv'][0]['status condition']);
        $this->assertSame('Score', $files['runs.csv'][0]['group']);
        $this->assertSame('1', $files['runs.csv'][0]['turns_spoken']);
        $this->assertSame('1', $files['runs.csv'][0]['turns_failed_without_speaking']);

        $participants = collect($files['participants.csv'])->keyBy('expert_uuid');
        $this->assertSame('true', $participants[$alex->uuid]['title-holder']);
        $this->assertSame('', $participants[$kim->uuid]['title-holder']);
        $this->assertSame('0', $participants[$kim->uuid]['turns']);
        $this->assertSame('1', $participants[$alex->uuid]['turn_share']);

        [$first, $failed] = $files['turns.csv'];
        $this->assertSame('1', $first['spoken']);
        $this->assertSame('5', $first['speaker_bid']);
        $this->assertSame('100', $first['tokens_in']);
        $this->assertSame('', $first['tokens_reasoning']);
        $this->assertSame('0', $failed['spoken']);
        $this->assertSame('', $failed['words']);
        $this->assertSame('HTTP 500', $failed['error']);
    }

    public function test_the_question_route_exports_its_runs_and_the_command_writes_a_file(): void
    {
        $viewer = User::factory()->create();
        $run = Project::factory()->create(['user_id' => $viewer->id, 'title' => 'School']);
        $run->addContributingUser($viewer);
        $question = $viewer->questions()->create(['name' => 'Status effect', 'definition' => []]);

        $this->actingAs($viewer)->get(route('statistics.export', $question))->assertOk()->assertDownload();
        $this->actingAs(User::factory()->create())->get(route('statistics.export', $question))->assertNotFound();

        $path = tempnam(sys_get_temp_dir(), 'study');
        $this->artisan('study:export-turns', ['path' => $path, '--user' => $viewer->id])->assertSuccessful();
        $this->assertCount(1, $this->read($path)['runs.csv']);
    }
}
