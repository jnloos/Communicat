<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleExporter;
use App\Services\Transfer\BundleReader;
use App\Services\Transfer\BundleWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TemporaryFiles;
use Tests\TestCase;

class BundleCommandsTest extends TestCase
{
    use RefreshDatabase, TemporaryFiles;

    private function path(): string
    {
        return $this->temporaryPath();
    }

    public function test_export_with_one_project_writes_a_project_bundle(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['user_id' => $admin->id]);
        $path = $this->path();

        $this->artisan('bundle:export', ['path' => $path, '--project' => [$project->id]])->assertSuccessful();

        $this->assertSame(Bundle::KIND_PROJECT, BundleReader::open($path)->manifest()['kind']);
    }

    public function test_export_with_one_expert_writes_an_expert_bundle(): void
    {
        $expert = Expert::factory()->create();
        $path = $this->path();

        $this->artisan('bundle:export', ['path' => $path, '--expert' => [$expert->id]])->assertSuccessful();

        $this->assertSame(Bundle::KIND_EXPERT, BundleReader::open($path)->manifest()['kind']);
    }

    public function test_export_refuses_two_projects_and_writes_no_file(): void
    {
        $projects = Project::factory()->count(2)->create();
        $path = $this->path();
        $this->assertFileDoesNotExist($path);

        $this->artisan('bundle:export', ['path' => $path, '--project' => $projects->pluck('id')->all()])
            ->expectsOutputToContain('Give exactly one --project, exactly one --expert, or neither for everything.')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_export_refuses_a_project_together_with_an_expert(): void
    {
        $project = Project::factory()->create();
        $expert = Expert::factory()->create();

        $this->artisan('bundle:export', ['path' => $this->path(), '--project' => [$project->id], '--expert' => [$expert->id]])
            ->expectsOutputToContain('exactly one')
            ->assertFailed();
    }

    public function test_two_options_say_exactly_one_even_when_an_id_is_missing(): void
    {
        $project = Project::factory()->create();

        $this->artisan('bundle:export', ['path' => $this->path(), '--project' => [$project->id], '--expert' => [998]])
            ->expectsOutputToContain('Give exactly one --project, exactly one --expert, or neither for everything.')
            ->doesntExpectOutputToContain('No expert with id 998.')
            ->assertFailed();
    }

    public function test_export_names_a_missing_project_or_expert(): void
    {
        $path = $this->path();

        $this->artisan('bundle:export', ['path' => $path, '--project' => [999]])
            ->expectsOutputToContain('No project with id 999.')->assertFailed();
        $this->artisan('bundle:export', ['path' => $path, '--expert' => [998]])
            ->expectsOutputToContain('No expert with id 998.')->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_both_commands_fail_cleanly_for_an_unknown_user_or_without_an_admin(): void
    {
        $path = $this->path();
        User::factory()->create(['is_admin' => false]);

        $this->artisan('bundle:export', ['path' => $path, '--user' => 999])
            ->expectsOutputToContain('No user with id 999.')->assertFailed();
        $this->artisan('bundle:export', ['path' => $path])
            ->expectsOutputToContain('No admin user; pass --user.')->assertFailed();
        $this->artisan('bundle:import', ['path' => $path, '--user' => 999])
            ->expectsOutputToContain('No user with id 999.')->assertFailed();
        $this->artisan('bundle:import', ['path' => $path])
            ->expectsOutputToContain('No admin user; pass --user.')->assertFailed();
    }

    public function test_a_dry_run_writes_nothing_and_says_so(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['user_id' => $admin->id]);
        $path = $this->path();
        app(BundleExporter::class)->project($project, $path);
        DB::table('projects')->delete();

        $this->artisan('bundle:import', ['path' => $path, '--user' => $admin->id, '--dry-run' => true])
            ->expectsOutputToContain('Preview only: nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, Project::count());
    }

    public function test_import_fails_when_a_project_fails(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['user_id' => $admin->id]);
        $source = $this->path();
        app(BundleExporter::class)->project($project, $source);
        $reader = BundleReader::open($source);
        $entry = Bundle::projectEntry($project->uuid);
        $data = $reader->json($entry);
        $data['jobs'] = [['key' => 1, 'started_at' => 'not a date']];
        $broken = $this->path();
        $writer = new BundleWriter($broken);
        $writer->put(Bundle::MANIFEST, $reader->manifest());
        $writer->put(Bundle::TAGS, $reader->json(Bundle::TAGS));
        $writer->put(Bundle::EXPERTS, $reader->json(Bundle::EXPERTS));
        $writer->put($entry, $data);
        $writer->close();
        DB::table('projects')->delete();

        $this->artisan('bundle:import', ['path' => $broken, '--user' => $admin->id])->assertFailed();

        $this->assertSame(0, Project::count());
    }
}
