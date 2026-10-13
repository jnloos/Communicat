<?php

namespace Tests\Feature\StudyExport;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
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
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $zip->getFromName($file));
            rewind($stream);
            $header = fgetcsv($stream, escape: '');
            $rows = [];

            while (($line = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = array_combine($header, $line);
            }

            $files[$file] = $rows;
        }

        return $files;
    }

    private function log(Project $run, int $turn, array $attributes = []): JobLog
    {
        return JobLog::create($attributes + ['job_class' => 'x', 'project_id' => $run->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => $turn]);
    }

    private function export(Project $run, User $viewer): array
    {
        $path = tempnam(sys_get_temp_dir(), 'study');
        app(StudyExport::class)->write(collect([$run]), $viewer, $path);

        return $this->read($path);
    }

    /** @return array{User, Project, Expert, Expert} */
    private function makeRun(): array
    {
        $viewer = User::factory()->create();
        [$alex, $kim] = Expert::factory()->count(2)->create()->all();
        $run = Project::factory()->create(['user_id' => $viewer->id, 'pipeline' => 'ScorePipeline']);
        $run->addContributingUser($viewer);
        $run->addContributingExpert($alex);
        $run->addContributingExpert($kim);

        return [$viewer, $run, $alex, $kim];
    }

    public function test_a_tag_category_named_like_a_fixed_column_gets_its_own_column(): void
    {
        [$viewer, $run, $alex] = $this->makeRun();
        $group = TagCategory::create(['scope' => TagScope::Project, 'name' => 'Group ']);
        $run->setTag($group, $group->values()->create(['name' => 'mine']));
        $turns = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'turns']);
        $alex->setTag($turns, $turns->values()->create(['name' => 'many']));
        $this->log($run, 1, ['expert_id' => $alex->id, 'seat' => 1, 'words' => 10, 'chars' => 50]);

        $files = $this->export($run, $viewer);

        $this->assertSame('mine', $files['runs.csv'][0]['tag Group ']);
        $this->assertSame('', $files['runs.csv'][0]['group']);
        $participants = collect($files['participants.csv'])->keyBy('expert_uuid');
        $this->assertSame('many', $participants[$alex->uuid]['tag turns']);
        $this->assertSame('1', $participants[$alex->uuid]['turns']);
    }

    public function test_token_sums_are_empty_when_any_call_of_the_turn_did_not_report(): void
    {
        [$viewer, $run, $alex] = $this->makeRun();
        $partial = $this->log($run, 1, ['expert_id' => $alex->id, 'words' => 5, 'chars' => 20]);
        PromptLog::create(['job_log_id' => $partial->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 10, 'tokens_out' => 2, 'tokens_reasoning' => 64]);
        PromptLog::create(['job_log_id' => $partial->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 10, 'tokens_out' => 3, 'tokens_reasoning' => null]);
        $full = $this->log($run, 2, ['expert_id' => $alex->id, 'words' => 5, 'chars' => 20]);
        PromptLog::create(['job_log_id' => $full->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 10, 'tokens_out' => 2, 'tokens_reasoning' => 64]);
        PromptLog::create(['job_log_id' => $full->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 5, 'tokens_out' => 3, 'tokens_reasoning' => 6]);

        [$one, $two] = $this->export($run, $viewer)['turns.csv'];

        $this->assertSame('20', $one['tokens_in']);
        $this->assertSame('5', $one['tokens_out']);
        $this->assertSame('', $one['tokens_reasoning']);
        $this->assertSame('15', $two['tokens_in']);
        $this->assertSame('70', $two['tokens_reasoning']);
    }

    public function test_a_speaker_who_left_the_run_stays_a_participant(): void
    {
        [$viewer, $run, $alex, $kim] = $this->makeRun();
        $this->log($run, 1, ['expert_id' => $alex->id, 'seat' => 1, 'words' => 30, 'chars' => 150]);
        $this->log($run, 2, ['expert_id' => $kim->id, 'seat' => 2, 'words' => 10, 'chars' => 50]);
        $run->removeContributingExpert($alex);

        $participants = collect($this->export($run, $viewer)['participants.csv'])->keyBy('expert_uuid');

        $this->assertCount(2, $participants);
        $this->assertSame('1', $participants[$alex->uuid]['turns']);
        $this->assertSame('', $participants[$alex->uuid]['seat']);
        $this->assertSame('30', $participants[$alex->uuid]['words']);
        $this->assertSame('2', $participants[$kim->uuid]['seat']);
    }

    public function test_selector_signals_addressee_and_values(): void
    {
        [$viewer, $run, $alex, $kim] = $this->makeRun();
        $tag = TagCategory::create(['scope' => TagScope::Project, 'name' => 'untouched']);
        $first = $this->log($run, 1, ['expert_id' => $alex->id, 'seat' => 1, 'words' => 30, 'chars' => 150, 'thought_words' => 7, 'thought_chars' => 33,
            'selection' => ['selector' => 'HighestBidSelector', 'speaker_id' => $alex->id, 'signals' => ['bids' => ["E{$alex->id}" => 4], 'fallbacks' => ['E9']], 'tie_broken' => true]]);
        PromptLog::create(['job_log_id' => $first->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 1, 'tokens_out' => 12, 'tokens_reasoning' => 0]);
        $second = $this->log($run, 2, ['expert_id' => $kim->id, 'seat' => 2, 'words' => 10, 'chars' => 50,
            'selection' => ['selector' => 'OrchestratorSelector', 'speaker_id' => $kim->id, 'signals' => ['token' => "E{$kim->id}", 'fallback' => true], 'tie_broken' => false]]);
        $third = $this->log($run, 3, ['expert_id' => $kim->id, 'seat' => 2, 'words' => 10, 'chars' => 50,
            'selection' => ['selector' => 'BlindJudgeSelector', 'speaker_id' => $kim->id, 'signals' => ['label' => 'D', 'order' => ['A' => $alex->id, 'D' => $kim->id], 'fallback' => false], 'tie_broken' => false]]);
        $message = new Message;
        $message->forceFill(['project_id' => $run->id, 'expert_id' => $alex->id, 'job_log_id' => $first->id, 'content' => "line one\nline, two \"quoted\"", 'addressee_expert_id' => $kim->id])->save();

        $files = $this->export($run, $viewer);
        [$a, $b, $c] = $files['turns.csv'];

        $this->assertSame($kim->uuid, $a['addressee_expert_uuid']);
        $this->assertSame('7', $a['thought_words']);
        $this->assertSame('12', $a['tokens_out']);
        $this->assertSame('1', $a['fallback']);
        $this->assertSame('1', $a['tie_broken']);
        $this->assertSame('4', $a['speaker_bid']);
        $this->assertSame('', $b['addressee_expert_uuid']);
        $this->assertSame('1', $b['fallback']);
        $this->assertSame('0', $b['tie_broken']);
        $this->assertSame('', $b['speaker_bid']);
        $this->assertSame('D', $c['judge_label']);
        $this->assertSame('0', $c['fallback']);
        $this->assertSame('', $a['judge_label']);

        $participants = collect($files['participants.csv'])->keyBy('expert_uuid');
        $this->assertSame('0.333333', $participants[$alex->uuid]['turn_share']);
        $this->assertSame('0.6', $participants[$alex->uuid]['word_share']);
        $this->assertSame('30', $participants[$alex->uuid]['words_per_turn']);
        $this->assertSame('10', $participants[$kim->uuid]['words_per_turn']);
        $this->assertArrayHasKey('untouched', $files['runs.csv'][0]);
        $this->assertSame('', $files['runs.csv'][0]['untouched']);
    }

    public function test_empty_files_still_carry_their_header(): void
    {
        $viewer = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'study');

        app(StudyExport::class)->write(collect(), $viewer, $path);

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertStringStartsWith('run_uuid,title,', $zip->getFromName('runs.csv'));
        $this->assertStringStartsWith('run_uuid,expert_uuid,', $zip->getFromName('participants.csv'));
        $this->assertStringStartsWith('run_uuid,turn_index,', $zip->getFromName('turns.csv'));
    }

    public function test_the_download_holds_the_three_files_and_only_the_viewers_runs(): void
    {
        $owner = User::factory()->create();
        $mine = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Mine']);
        $mine->addContributingUser($owner);
        $other = User::factory()->create();
        $theirs = Project::factory()->create(['user_id' => $other->id, 'title' => 'Theirs']);
        $theirs->addContributingUser($other);
        $question = $owner->questions()->create(['name' => 'Q', 'definition' => []]);

        $response = $this->actingAs($owner)->get(route('statistics.export', $question));
        $response->assertDownload();
        $files = $this->read($response->baseResponse->getFile()->getPathname());

        $this->assertCount(1, $files['runs.csv']);
        $this->assertSame($mine->uuid, $files['runs.csv'][0]['run_uuid']);
        $this->assertSame([], $files['turns.csv']);
    }

    public function test_a_non_owner_can_export_a_template(): void
    {
        $admin = User::factory()->create();
        $template = $admin->questions()->create(['name' => 'T', 'definition' => [], 'is_template' => true]);
        $reader = User::factory()->create();
        $run = Project::factory()->create(['user_id' => $reader->id]);
        $run->addContributingUser($reader);

        $response = $this->actingAs($reader)->get(route('statistics.export', $template));
        $response->assertOk()->assertDownload();
        $this->assertCount(1, $this->read($response->baseResponse->getFile()->getPathname())['runs.csv']);
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
        $this->assertSame('', $participants[$kim->uuid]['words_per_turn']);
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
