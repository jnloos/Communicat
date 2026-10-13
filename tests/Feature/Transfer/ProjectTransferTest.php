<?php

namespace Tests\Feature\Transfer;

use App\Discussion\Metrics\SpeakingShares;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\ProjectTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTransferTest extends TestCase
{
    use RefreshDatabase;

    private function studyRun(User $owner): Project
    {
        [$alex, $kim] = Expert::factory()->count(2)->create()->all();
        $project = Project::factory()->create(['user_id' => $owner->id, 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5-mini', 'seed' => 42, 'turn_budget' => 60, 'run_config' => ['pipeline' => 'ScorePipeline']]);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $project->setTag($condition, TagValue::create(['tag_category_id' => $condition->id, 'name' => 'status']));
        $holder = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $alex->setTag($holder, TagValue::create(['tag_category_id' => $holder->id, 'name' => 'true']));
        $project->addContributingExpert($alex);
        $project->addContributingExpert($kim);
        $job = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'turn_index' => 1, 'expert_id' => $alex->id, 'seat' => 1, 'words' => 3, 'chars' => 15, 'selection' => ['selector' => 'HighestBidSelector', 'speaker_id' => $alex->id, 'signals' => ['bids' => ["E{$alex->id}" => 5]]]]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'gpt-5-mini', 'prompt' => 'p', 'response' => 'r', 'purpose' => 'think', 'expert_id' => $kim->id, 'tokens_in' => 10, 'tokens_out' => 5]);
        JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'failed', 'started_at' => now()->subSeconds(30), 'finished_at' => now(), 'turn_index' => 2, 'expert_id' => $kim->id, 'seat' => 2, 'words' => 7, 'chars' => 35, 'error' => 'summarize failed']);
        $first = new Message;
        $first->forceFill(['project_id' => $project->id, 'expert_id' => $alex->id, 'addressee_expert_id' => $kim->id, 'job_log_id' => $job->id, 'content' => 'Hello Kim'])->save();
        Summary::create(['project_id' => $project->id, 'expert_id' => $kim->id, 'content' => 'Kim thinks.']);
        $project->update(['long_term_memory' => 'So far.', 'summarized_until_message_id' => $first->id]);

        return $project->fresh();
    }

    public function test_a_run_survives_export_and_import_into_an_empty_project_table(): void
    {
        $owner = User::factory()->create();
        $source = $this->studyRun($owner);
        $sharesBefore = app(SpeakingShares::class)->forProject($source);
        $data = (new ProjectTransfer)->export($source);
        $source->delete();

        $report = new ImportReport;
        $copy = (new ProjectTransfer)->import($data, $owner, $report);

        $this->assertNotNull($copy);
        $this->assertSame($data['uuid'], $copy->uuid);
        $this->assertSame(42, (int) $copy->seed);
        $this->assertSame(['pipeline' => 'ScorePipeline'], $copy->run_config);
        $this->assertSame([1, 2], $copy->experts()->get()->pluck('pivot.seat')->map(fn ($seat) => (int) $seat)->all());
        $this->assertSame('Hello Kim', $copy->messages()->sole()->content);
        $this->assertSame($copy->messages()->sole()->id, (int) $copy->summarized_until_message_id);
        $this->assertSame(2, JobLog::where('project_id', $copy->id)->count());
        $this->assertSame(10, PromptLog::whereHas('jobLog', fn ($q) => $q->where('project_id', $copy->id))->sole()->tokens_in);
        $this->assertSame(JobLog::where('project_id', $copy->id)->where('turn_index', 1)->sole()->id, $copy->messages()->sole()->job_log_id);
        $this->assertSame('Kim thinks.', Summary::where('project_id', $copy->id)->sole()->content);
        $this->assertEquals($sharesBefore->speakers, app(SpeakingShares::class)->forProject($copy)->speakers);
        $this->assertTrue($copy->hasContributor($owner));
        $this->assertSame(['status condition' => 'status'], $data['tags']);
        $this->assertSame($this->normalise($data), $this->normalise((new ProjectTransfer)->export($copy)));
        $this->assertSame(2, $sharesBefore->spokenTurns);
    }

    /** Local keys are per file: compare them by position, everything else verbatim. */
    private function normalise(array $data): array
    {
        $jobs = array_flip(array_column($data['jobs'], 'key'));
        $messages = array_flip(array_column($data['messages'], 'key'));

        foreach ($data['jobs'] as $i => $job) {
            $data['jobs'][$i]['key'] = $i;
        }
        foreach ($data['messages'] as $i => $message) {
            $data['messages'][$i]['key'] = $i;
            $data['messages'][$i]['job'] = $message['job'] === null ? null : $jobs[$message['job']];
        }
        $data['summarized_until_message'] = $data['summarized_until_message'] === null ? null : $messages[$data['summarized_until_message']];

        return $data;
    }

    public function test_skipped_project_tags_are_reported_on_the_created_entry(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        Project::query()->delete();
        $data['tags'] = ['No such category' => 'x'];
        $report = new ImportReport;

        $this->assertNotNull((new ProjectTransfer)->import($data, $owner, $report));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::CREATED));
        $this->assertStringContainsString('No such category: x', $report->entries(ImportReport::PROJECTS)[0]['note']);
    }

    public function test_an_unknown_model_skips_the_project(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        Project::query()->delete();
        $report = new ImportReport;

        JobLog::query()->delete();
        Message::query()->delete();

        $this->assertNull((new ProjectTransfer)->import(['model' => 'nope-1'] + $data, $owner, $report));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
        $this->assertSame(__('transfer.notes.unknown_model', ['model' => 'nope-1']), $report->entries(ImportReport::PROJECTS)[0]['note']);
        $this->assertSame(0, Project::count());
        $this->assertSame(0, JobLog::count());
        $this->assertSame(0, Message::count());
    }

    public function test_a_failing_write_rolls_the_whole_project_back(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        Project::query()->delete();
        JobLog::query()->delete();
        Message::query()->delete();
        $data['summaries'][0]['expert'] = null;
        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::FAILED));
        $this->assertSame(0, Project::count());
        $this->assertSame(0, JobLog::count());
        $this->assertSame(0, Message::count());
    }

    public function test_an_existing_project_is_skipped(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
    }

    public function test_an_unknown_pipeline_or_a_missing_expert_skips_the_project_with_a_reason(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        Project::query()->delete();
        $report = new ImportReport;

        $unknownPipeline = ['pipeline' => 'GonePipeline'] + $data;
        $this->assertNull((new ProjectTransfer)->import($unknownPipeline, $owner, $report));

        $missingExpert = $data;
        $missingExpert['seats'][0]['expert'] = '9d1e2f3a-4b5c-4d6e-8f7a-0b1c2d3e4f5a';
        $this->assertNull((new ProjectTransfer)->import($missingExpert, $owner, $report));

        $this->assertSame(2, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
        $this->assertSame(0, Project::count());
    }

    public function test_it_names_every_expert_the_project_refers_to(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $gone = Expert::factory()->create();
        PromptLog::create(['job_log_id' => JobLog::where('turn_index', 1)->sole()->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'expert_id' => $gone->id]);

        $this->assertContains($gone->id, (new ProjectTransfer)->expertIds($project));
    }
}
