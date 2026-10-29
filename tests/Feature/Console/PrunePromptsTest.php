<?php

namespace Tests\Feature\Console;

use App\Actions\Projects\DeleteProject;
use App\Console\Commands\PrunePrompts;
use App\Discussion\Logging\PromptArchiver;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptArchive;
use App\Models\PromptLog;
use App\Services\Transfer\ProjectExporter;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\TestCase;

class PrunePromptsTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $age): PromptLog
    {
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        $log = PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'Long prompt', 'response' => 'Answer', 'tokens_in' => 10, 'tokens_out' => 5]);
        $log->forceFill(['created_at' => now()->sub($age)])->save();

        return $log;
    }

    public function test_it_archives_old_prompt_texts_and_keeps_everything_else(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $fresh = $this->log('1 hour');

        $this->artisan('prompts:prune')->assertSuccessful();

        $old = $old->fresh();
        $this->assertSame('', $old->prompt);
        $this->assertNotNull($old->prompt_archive_id);
        $this->assertSame('Long prompt', $old->prompt_text);
        $this->assertSame('Answer', $old->response);
        $this->assertSame(10, $old->tokens_in);
        $this->assertSame('Long prompt', $fresh->fresh()->prompt);
        $this->assertNull($fresh->fresh()->prompt_archive_id);
    }

    public function test_one_archive_holds_all_old_prompts_of_a_project(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $first = $this->log('25 hours');
        $second = PromptLog::create(['job_log_id' => $first->job_log_id, 'model' => 'm', 'prompt' => 'Second prompt ü', 'response' => 'A']);
        $second->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame(1, PromptArchive::count());
        $this->assertSame($first->fresh()->prompt_archive_id, $second->fresh()->prompt_archive_id);
        $this->assertSame('Second prompt ü', $second->fresh()->prompt_text);
    }

    public function test_a_broken_archive_payload_reads_as_empty_text_and_does_not_throw(): void
    {
        $log = $this->log('1 hour');
        $archive = PromptArchive::create(['project_id' => $log->jobLog->project_id, 'payload' => 'not-a-zstd-frame']);
        $log->forceFill(['prompt' => '', 'prompt_archive_id' => $archive->id])->save();

        $this->assertSame('', $log->fresh()->prompt_text);
    }

    public function test_the_payload_is_a_small_zstd_frame_that_survives_the_binary_column(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $text = str_repeat('Same system prompt, same persona, same history. ', 200);
        $old->forceFill(['prompt' => $text])->save();

        $this->artisan('prompts:prune')->assertSuccessful();

        $payload = PromptArchive::findOrFail($old->fresh()->prompt_archive_id)->payload;
        $this->assertSame("\x28\xB5\x2F\xFD", substr($payload, 0, 4));
        $this->assertLessThan(strlen($text) / 10, strlen($payload));
        $this->assertSame($text, $old->fresh()->prompt_text);
    }

    public function test_raw_bytes_with_nul_and_invalid_utf8_roundtrip_through_the_column(): void
    {
        $bytes = "a\x00b\xFF\xFE\x80".random_bytes(256);
        $archive = PromptArchive::create(['project_id' => Project::factory()->create()->id, 'payload' => $bytes]);

        $this->assertSame($bytes, $archive->fresh()->payload);
    }

    public function test_without_zstd_it_aborts_before_touching_any_prompt(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $orphan = PromptLog::create(['model' => 'm', 'prompt' => 'Long prompt', 'response' => 'A']);
        $orphan->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->app->instance(PromptArchiver::class, new PromptArchiver(zstd: false));

        $this->artisan('prompts:prune')->expectsOutputToContain('ext-zstd')->assertFailed();

        $this->assertSame(0, PromptArchive::count());
        $this->assertSame('Long prompt', $old->fresh()->prompt);
        $this->assertSame('Long prompt', $orphan->fresh()->prompt);
    }

    public function test_a_prompt_with_invalid_utf8_is_still_archived(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $old->forceFill(['prompt' => "bad \xB1 byte"])->save();

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertNotNull($old->fresh()->prompt_archive_id);
        $this->assertStringStartsWith('bad ', $old->fresh()->prompt_text);
    }

    public function test_two_projects_get_two_readable_archives(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $first = $this->log('25 hours');
        $second = $this->log('26 hours');
        $second->forceFill(['prompt' => 'Other prompt'])->save();

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame(2, PromptArchive::count());
        $this->assertSame('Long prompt', $first->fresh()->prompt_text);
        $this->assertSame('Other prompt', $second->fresh()->prompt_text);
    }

    public function test_a_second_run_leaves_an_existing_archive_untouched(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $this->artisan('prompts:prune')->assertSuccessful();
        $archiveId = $old->fresh()->prompt_archive_id;

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame(1, PromptArchive::count());
        $this->assertSame($archiveId, $old->fresh()->prompt_archive_id);
    }

    public function test_an_archived_prompt_is_exported_as_text(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $this->artisan('prompts:prune')->assertSuccessful();

        $export = app(ProjectExporter::class)->export(Project::find($old->jobLog->project_id));

        $this->assertSame('Long prompt', $export['jobs'][0]['prompts'][0]['prompt']);
    }

    public function test_a_call_without_a_project_gets_the_old_note(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $orphan = PromptLog::create(['model' => 'm', 'prompt' => 'Long prompt', 'response' => 'A']);
        $orphan->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame(PrunePrompts::marker(24), $orphan->fresh()->prompt);
        $this->assertNull($orphan->fresh()->prompt_archive_id);
    }

    public function test_a_failed_project_keeps_its_text_while_others_are_archived_and_the_command_fails(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $broken = $this->log('25 hours');
        $fine = $this->log('25 hours');
        $this->app->instance(PromptArchiver::class, new class($broken->jobLog->project_id) extends PromptArchiver
        {
            public function __construct(private int $brokenProject)
            {
                parent::__construct();
            }

            protected function archiveProject(int $projectId, CarbonInterface $cutoff): int
            {
                throw_if($projectId === $this->brokenProject, new \RuntimeException('boom'));

                return parent::archiveProject($projectId, $cutoff);
            }
        });

        $this->artisan('prompts:prune')->expectsOutputToContain('1 projects could not be archived')->assertFailed();

        $this->assertSame('Long prompt', $broken->fresh()->prompt);
        $this->assertNull($broken->fresh()->prompt_archive_id);
        $this->assertNotNull($fine->fresh()->prompt_archive_id);
    }

    public function test_the_export_aborts_when_an_archived_prompt_cannot_be_unpacked(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $this->artisan('prompts:prune')->assertSuccessful();
        PromptArchive::$zstdOverride = false;

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ext-zstd is missing');
            app(ProjectExporter::class)->export(Project::find($old->jobLog->project_id));
        } finally {
            PromptArchive::$zstdOverride = null;
        }
    }

    public function test_deleting_a_project_removes_its_archives_and_calls(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $this->artisan('prompts:prune')->assertSuccessful();

        app(DeleteProject::class)->handle(Project::find($old->jobLog->project_id));

        $this->assertSame(0, PromptArchive::count());
        $this->assertSame(0, PromptLog::count());
    }

    public function test_it_deletes_uploads_left_behind_beyond_the_retention(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        Storage::fake(FileUploadConfiguration::disk());
        $storage = FileUploadConfiguration::storage();
        $old = FileUploadConfiguration::path('old.zip');
        $fresh = FileUploadConfiguration::path('fresh.zip');
        $storage->put($old, 'prompts');
        $storage->put($fresh, 'prompts');
        touch($storage->path($old), now()->subHours(25)->timestamp);

        $this->artisan('prompts:prune')->expectsOutput('1 left-behind uploads deleted.')->assertSuccessful();

        $this->assertFalse($storage->exists($old));
        $this->assertTrue($storage->exists($fresh));
    }

    public function test_the_hourly_query_finds_old_prompts_by_an_index(): void
    {
        $plan = collect(DB::select('explain query plan select id from prompt_logs where created_at < ?', [now()]))->pluck('detail')->implode(' ');

        $this->assertStringContainsString('prompt_logs_created_at_index', $plan);
    }

    public function test_zero_hours_keeps_every_prompt(): void
    {
        config(['discussion.prompt_retention_hours' => 0]);
        $old = $this->log('30 days');

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame('Long prompt', $old->fresh()->prompt);
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('2 days');

        $this->artisan('prompts:prune');
        $updatedAt = $old->fresh()->updated_at;
        $this->artisan('prompts:prune')->expectsOutputToContain('0 prompt');

        $this->assertEquals($updatedAt, $old->fresh()->updated_at);
    }

    public function test_the_hourly_entry_does_not_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'prompts:prune'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }
}
