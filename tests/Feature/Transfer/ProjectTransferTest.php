<?php

namespace Tests\Feature\Transfer;

use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\CallStatus;
use App\Discussion\Values\JobStatus;
use App\Discussion\Values\Purpose;
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
use App\Services\Transfer\ExpertTransfer;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\ProjectTransfer;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_statuses_and_purposes_travel_as_strings_and_come_back_as_enums(): void
    {
        $owner = User::factory()->create();
        $source = $this->studyRun($owner);
        $data = (new ProjectTransfer)->export($source);
        $source->delete();

        $this->assertSame(['success', 'failed'], array_column($data['jobs'], 'status'));
        $this->assertSame('ok', $data['jobs'][0]['prompts'][0]['status']);
        $this->assertSame('think', $data['jobs'][0]['prompts'][0]['purpose']);

        $copy = (new ProjectTransfer)->import(json_decode(json_encode($data), true), $owner, new ImportReport);

        $this->assertSame([JobStatus::Success, JobStatus::Failed], JobLog::where('project_id', $copy->id)->orderBy('turn_index')->pluck('status')->all());
        $call = PromptLog::whereHas('jobLog', fn ($q) => $q->where('project_id', $copy->id))->sole();
        $this->assertSame(CallStatus::Ok, $call->status);
        $this->assertSame(Purpose::Think, $call->purpose);
    }

    public function test_an_unknown_status_fails_the_project_and_writes_nothing(): void
    {
        $owner = User::factory()->create();
        $source = $this->studyRun($owner);
        $data = (new ProjectTransfer)->export($source);
        $source->delete();
        $data['jobs'][0]['status'] = 'done';

        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::FAILED));
        $this->assertSame(0, JobLog::count());
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

    /** @return iterable<string, array{Closure(array): array}> */
    public static function brokenProjectFiles(): iterable
    {
        yield 'pipeline is a list' => [fn (array $data) => ['pipeline' => ['ScorePipeline']] + $data];
        yield 'seats is a text' => [fn (array $data) => ['seats' => 'all'] + $data];
        yield 'a seat without its expert' => [function (array $data) {
            unset($data['seats'][0]['expert']);

            return $data;
        }];
        yield 'a job without its key' => [function (array $data) {
            unset($data['jobs'][0]['key']);

            return $data;
        }];
        yield 'a message key that is no number' => [function (array $data) {
            $data['messages'][0]['key'] = 'first';

            return $data;
        }];
        yield 'a prompt that is no object' => [function (array $data) {
            $data['jobs'][0]['prompts'][0] = 'p';

            return $data;
        }];
        yield 'a time that is no time' => [function (array $data) {
            $data['jobs'][0]['started_at'] = 'yesterday-ish';

            return $data;
        }];
    }

    #[DataProvider('brokenProjectFiles')]
    public function test_a_project_file_of_the_wrong_shape_fails_alone_as_unreadable(Closure $break): void
    {
        $owner = User::factory()->create();
        $data = $break((new ProjectTransfer)->export($this->studyRun($owner)));
        Project::query()->delete();
        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));

        $this->assertSame(0, Project::count());
        $entry = $report->entries(ImportReport::PROJECTS)[0];
        $this->assertSame(ImportReport::FAILED, $entry['outcome']);
        $this->assertSame(__('transfer.notes.unreadable_entry'), $entry['note']);
    }

    public function test_it_names_every_expert_the_project_refers_to(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $gone = Expert::factory()->create();
        PromptLog::create(['job_log_id' => JobLog::where('turn_index', 1)->sole()->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'expert_id' => $gone->id]);

        $this->assertContains($gone->id, (new ProjectTransfer)->expertIds($project));
    }

    public function test_the_export_maps_every_original_expert_id_to_its_uuid(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $data = (new ProjectTransfer)->export($project);

        $expected = Expert::whereIn('id', (new ProjectTransfer)->expertIds($project))->pluck('uuid', 'id')
            ->mapWithKeys(fn ($uuid, $id) => [(string) $id => $uuid])->all();
        $this->assertSame($expected, $data['expert_ids']);
    }

    public function test_selection_ids_follow_experts_that_were_renumbered_on_import(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        [$alex, $kim] = $project->experts()->orderBy('seat')->get()->all();
        $a = $alex->id;
        $k = $kim->id;
        JobLog::where('project_id', $project->id)->where('turn_index', 1)->update(['selection' => json_encode([
            'selector' => 'HighestBidSelector', 'speaker_id' => $a, 'reasoning' => "E{$a} bid highest",
            'signals' => ['bids' => ["E{$a}" => 5, "E{$k}" => 1, 'E999' => 3], 'fallbacks' => ["E{$k}", 'E999']], 'tie_broken' => false,
        ])]);
        JobLog::where('project_id', $project->id)->where('turn_index', 2)->update(['selection' => json_encode([
            'selector' => 'BlindJudgeSelector', 'speaker_id' => $k,
            'signals' => ['label' => 'B', 'order' => ['A' => $a, 'B' => $k, 'C' => 999], 'fallback' => false, 'token' => "E{$k}"], 'tie_broken' => false,
        ])]);
        $project->update(['run_config' => ['pipeline' => 'ScorePipeline', 'cast' => [
            ['seat' => 1, 'expert_id' => $a, 'name' => 'Alex', 'role' => 'CEO'],
            ['seat' => 2, 'expert_id' => $k, 'name' => 'Kim', 'role' => 'Lead'],
            ['seat' => 3, 'expert_id' => 999, 'name' => 'Gone', 'role' => 'Lead'],
        ]]]);
        $data = (new ProjectTransfer)->export($project->fresh());
        $experts = [(new ExpertTransfer)->export($alex), (new ExpertTransfer)->export($kim)];
        Project::query()->delete();
        Expert::query()->delete();
        // Two other experts now hold the original ids, so both are renumbered.
        Expert::factory()->create(['id' => $a]);
        Expert::factory()->create(['id' => $k]);

        (new ExpertTransfer)->importAll($experts, new ImportReport);
        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $newA = Expert::where('uuid', $alex->uuid)->sole()->id;
        $newK = Expert::where('uuid', $kim->uuid)->sole()->id;
        $this->assertNotSame($a, $newA);
        $this->assertNotSame($k, $newK);
        $first = JobLog::where('project_id', $copy->id)->where('turn_index', 1)->sole()->selection;
        $this->assertSame($newA, $first['speaker_id']);
        $this->assertSame(["E{$newA}" => 5, "E{$newK}" => 1, 'E999' => 3], $first['signals']['bids']);
        $this->assertSame(["E{$newK}", 'E999'], $first['signals']['fallbacks']);
        $this->assertSame("E{$a} bid highest", $first['reasoning']);
        $second = JobLog::where('project_id', $copy->id)->where('turn_index', 2)->sole()->selection;
        $this->assertSame($newK, $second['speaker_id']);
        $this->assertSame(['A' => $newA, 'B' => $newK, 'C' => 999], $second['signals']['order']);
        $this->assertSame("E{$newK}", $second['signals']['token']);
        $this->assertSame('B', $second['signals']['label']);
        $this->assertSame([$newA, $newK, 999], array_column($copy->run_config['cast'], 'expert_id'));
        $this->assertSame(['Alex', 'Kim', 'Gone'], array_column($copy->run_config['cast'], 'name'));
        $this->assertSame('ScorePipeline', $copy->run_config['pipeline']);
    }

    public function test_a_cast_member_who_left_without_speaking_is_renumbered_with_the_cast(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $robin = Expert::factory()->create(['name' => 'Robin']);
        $r = $robin->id;
        $project->update(['run_config' => ['pipeline' => 'ScorePipeline', 'cast' => [['seat' => 3, 'expert_id' => $r, 'name' => 'Robin', 'role' => 'Lead']]]]);

        $this->assertContains($r, (new ProjectTransfer)->expertIds($project->fresh()));

        $data = (new ProjectTransfer)->export($project->fresh());
        $experts = $project->experts()->get()->push($robin)->map(fn (Expert $expert) => (new ExpertTransfer)->export($expert))->all();
        Project::query()->delete();
        Expert::query()->delete();
        Expert::factory()->create(['id' => $r]);

        (new ExpertTransfer)->importAll($experts, new ImportReport);
        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $newR = Expert::where('uuid', $robin->uuid)->sole()->id;
        $this->assertNotSame($r, $newR);
        $this->assertSame([$newR], array_column($copy->run_config['cast'], 'expert_id'));
    }

    public function test_a_cast_member_missing_here_skips_the_project_instead_of_keeping_a_foreign_id(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $robin = Expert::factory()->create(['name' => 'Robin']);
        $project->update(['run_config' => ['pipeline' => 'ScorePipeline', 'cast' => [['seat' => 3, 'expert_id' => $robin->id, 'name' => 'Robin', 'role' => 'Lead']]]]);
        $data = (new ProjectTransfer)->export($project->fresh());
        Project::query()->delete();
        $robin->delete();
        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));

        $this->assertSame(ImportReport::SKIPPED, $report->entries(ImportReport::PROJECTS)[0]['outcome']);
        $this->assertStringContainsString($robin->uuid, $report->entries(ImportReport::PROJECTS)[0]['note']);
    }

    public function test_a_same_id_import_leaves_the_selection_byte_identical(): void
    {
        $owner = User::factory()->create();
        $project = $this->studyRun($owner);
        $before = JobLog::where('project_id', $project->id)->orderBy('turn_index')->pluck('selection')->all();
        $raw = DB::table('job_logs')->where('project_id', $project->id)->orderBy('turn_index')->pluck('selection')->all();
        $project->update(['run_config' => ['pipeline' => 'ScorePipeline', 'cast' => [['seat' => 1, 'expert_id' => $project->experts()->first()->id, 'name' => 'Alex', 'role' => 'CEO']]]]);
        $config = DB::table('projects')->where('id', $project->id)->value('run_config');
        $data = (new ProjectTransfer)->export($project);
        $project->delete();

        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $this->assertSame($before, JobLog::where('project_id', $copy->id)->orderBy('turn_index')->pluck('selection')->all());
        $this->assertSame($raw, DB::table('job_logs')->where('project_id', $copy->id)->orderBy('turn_index')->pluck('selection')->all());
        $this->assertSame($config, DB::table('projects')->where('id', $copy->id)->value('run_config'));
    }

    public function test_a_project_without_a_valid_uuid_is_skipped(): void
    {
        $owner = User::factory()->create();
        $data = (new ProjectTransfer)->export($this->studyRun($owner));
        Project::query()->delete();
        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import(['uuid' => 'x-1'] + $data, $owner, $report));
        $this->assertSame(__('transfer.notes.bad_uuid'), $report->entries(ImportReport::PROJECTS)[0]['note']);
        $this->assertSame(0, Project::count());
    }
}
