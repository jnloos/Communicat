<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleExporter;
use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\InvalidBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Concerns\TemporaryFiles;
use Tests\TestCase;

class BundleRoundTripTest extends TestCase
{
    use RefreshDatabase, TemporaryFiles;

    private function path(): string
    {
        return $this->temporaryPath();
    }

    private function study(User $admin): Project
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $expert = Expert::factory()->create();
        $expert->setTag($focus, $true);
        $project = Project::factory()->create(['user_id' => $admin->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'openai-gpt-5-mini']);
        $project->addContributingUser($admin);
        $project->addContributingExpert($expert);
        JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'expert_id' => $expert->id, 'words' => 4]);
        $group = ProjectGroup::create(['user_id' => $admin->id, 'name' => 'Round Robin', 'position' => 1]);
        $project->fileInGroupFor($admin, $group->id);
        $admin->questions()->create(['name' => 'Q', 'definition' => ['people' => ['tags' => [$focus->id => [$true->id]]]]]);

        return $project;
    }

    private function wipe(): void
    {
        foreach (['questions', 'project_groups', 'prompt_logs', 'messages', 'job_logs', 'summaries', 'project_contributors', 'projects', 'taggables', 'experts', 'tag_values', 'tag_categories'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_a_global_bundle_restores_everything_into_an_empty_database(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertFalse($report->hasFailures());
        $copy = Project::where('uuid', $project->uuid)->sole();
        $this->assertSame(1, JobLog::where('project_id', $copy->id)->count());
        $this->assertSame('Round Robin', ProjectGroup::find($copy->groupIdFor($admin))->name);
        $question = Question::sole();
        $this->assertSame([TagCategory::sole()->id => [TagCategory::sole()->values()->sole()->id]], $question->definition()->people->tags->toArray());
        $this->assertTrue(Expert::sole()->tagValues()->exists());
    }

    public function test_importing_the_same_bundle_twice_adds_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Expert::count());
        $this->assertSame(1, Question::count());
        $this->assertSame(0, $report->count(ImportReport::PROJECTS, ImportReport::CREATED));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
    }

    public function test_a_project_bundle_brings_its_experts_and_tags_but_no_groups_or_questions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->project($project, $path);
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Expert::count());
        $this->assertSame(1, TagCategory::count());
        $this->assertSame(0, ProjectGroup::count());
        $this->assertSame([ImportReport::TAGS, ImportReport::EXPERTS, ImportReport::PROJECTS], $report->sections());
    }

    public function test_a_preview_reports_like_the_import_and_writes_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);
        $this->wipe();
        $tables = ['tag_categories', 'tag_values', 'taggables', 'experts', 'projects', 'job_logs', 'messages', 'prompt_logs', 'project_groups', 'questions'];
        $counts = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $before = $counts();

        $preview = app(BundleImporter::class)->preview($path, $admin);

        $this->assertSame($before, $counts());
        $this->assertSame(1, $preview->count(ImportReport::PROJECTS, ImportReport::CREATED));
        $this->assertSame($preview->toArray(), app(BundleImporter::class)->import($path, $admin)->toArray());
    }

    public function test_the_console_commands_export_and_import(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();

        $this->artisan('bundle:export', ['path' => $path, '--user' => $admin->id])->assertSuccessful();
        $this->wipe();
        $this->artisan('bundle:import', ['path' => $path, '--user' => $admin->id])->assertSuccessful();

        $this->assertSame(1, Project::count());
    }

    public function test_a_broken_file_is_refused_before_anything_is_written(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $path = $this->path();
        file_put_contents($path, 'no zip');

        $this->expectException(InvalidBundle::class);
        app(BundleImporter::class)->import($path, $admin);
    }

    public function test_a_broken_section_fails_alone_and_the_rest_is_imported_and_reported(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString(Bundle::QUESTIONS, '{broken');
        $zip->close();
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertTrue($report->hasFailures());
        $this->assertSame([['name' => Bundle::QUESTIONS, 'outcome' => ImportReport::FAILED, 'note' => __('transfer.errors.broken_entry', ['name' => Bundle::QUESTIONS])]], $report->entries(ImportReport::QUESTIONS));
        $this->assertSame(1, $report->count(ImportReport::PROJECTS, ImportReport::CREATED));
        $this->assertSame(1, $report->count(ImportReport::GROUPS, ImportReport::CREATED));
        $this->assertTrue(Project::where('uuid', $project->uuid)->exists());
        $this->assertSame(0, Question::count());
    }

    public function test_an_unexpected_error_shows_a_generic_note_and_is_logged(): void
    {
        Exceptions::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->study($admin);
        $path = $this->path();
        app(BundleExporter::class)->everything($admin, $path);
        $this->wipe();
        Expert::creating(fn () => throw new \RuntimeException('SQLSTATE[23000]: secret table detail'));

        $report = app(BundleImporter::class)->import($path, $admin);

        $failed = collect($report->entries(ImportReport::EXPERTS))->firstWhere('outcome', ImportReport::FAILED);
        $this->assertSame(Bundle::EXPERTS, $failed['name']);
        $this->assertSame(__('transfer.notes.unexpected_error'), $failed['note']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($report->toArray()));
        Exceptions::assertReported(fn (\RuntimeException $exception) => str_contains($exception->getMessage(), 'SQLSTATE'));
    }
}
