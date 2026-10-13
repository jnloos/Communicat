<?php

namespace Tests\Feature\Transfer;

use App\Livewire\Settings\Transfer;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\BundleExporter;
use App\Services\Transfer\ImportReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class TransferSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function bundle(User $admin): UploadedFile
    {
        $project = Project::factory()->create(['user_id' => $admin->id, 'title' => 'School']);
        $project->addContributingExpert(Expert::factory()->create(['name' => 'Alex']));
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        app(BundleExporter::class)->project($project, $path);
        Project::query()->delete();
        Expert::query()->delete();

        return UploadedFile::fake()->createWithContent('school.zip', file_get_contents($path));
    }

    public function test_only_admins_reach_the_page(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create())->get(route('settings.transfer'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('settings.transfer'))->assertOk()->assertSee('Export everything');
    }

    public function test_an_upload_previews_then_imports_and_reports(): void
    {
        app()->setLocale('en');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $component = Livewire::test(Transfer::class)
            ->set('bundle', $this->bundle($admin))
            ->assertSet('stage', 'preview')
            ->assertSee('What would be imported')
            ->assertSee('School')
            ->assertSee('Alex');
        $this->assertSame(0, Project::count());

        $component->call('import')
            ->assertSet('stage', 'done')
            ->assertSee('Imported');
        $this->assertSame(1, Project::count());

        $component->call('startOver')->assertSet('stage', 'upload')->assertSet('report', []);
    }

    public function test_a_broken_file_shows_its_error_and_stays_on_upload(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Transfer::class)
            ->set('bundle', UploadedFile::fake()->createWithContent('x.zip', 'nope'))
            ->assertSet('stage', 'upload')
            ->assertHasErrors('bundle');
    }

    public function test_mounting_the_component_is_forbidden_for_non_admins(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Transfer::class)->assertForbidden();
    }

    public function test_import_from_the_upload_stage_writes_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $file = $this->bundle($admin);

        Livewire::test(Transfer::class)->set('bundle', $file)->call('startOver')->call('import');

        $this->assertSame(0, Project::count());
    }

    public function test_a_bad_file_after_a_good_preview_resets_to_upload_and_import_writes_nothing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(Transfer::class)
            ->set('bundle', $this->bundle($admin))
            ->assertSet('stage', 'preview')
            ->set('bundle', UploadedFile::fake()->createWithContent('x.zip', 'nope'))
            ->assertSet('stage', 'upload')
            ->assertSet('report', [])
            ->assertSet('bundle', null)
            ->assertHasErrors('bundle')
            ->call('import');

        $this->assertSame(0, Project::count());
    }

    public function test_an_import_that_throws_shows_the_error_at_the_upload_and_drops_the_file(): void
    {
        app()->setLocale('en');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $component = Livewire::test(Transfer::class)->set('bundle', $this->bundle($admin))->assertSet('stage', 'preview');
        $upload = $component->get('bundle');
        // The temporary file vanishes between preview and import.
        unlink($upload->getRealPath());

        $component->call('import')
            ->assertSet('stage', 'upload')
            ->assertSet('report', [])
            ->assertSet('bundle', null)
            ->assertHasErrors('bundle');

        $this->assertStringStartsWith(
            __('transfer.import.failed', ['message' => '']),
            $component->errors()->first('bundle'),
        );
        $this->assertSame(0, Project::count());
    }

    public function test_failed_entries_are_listed_first_and_their_section_is_open(): void
    {
        $report = new ImportReport;
        $report->add(ImportReport::PROJECTS, 'Good one', ImportReport::CREATED);
        $report->add(ImportReport::PROJECTS, 'Bad one', ImportReport::FAILED, 'boom');
        $report->add(ImportReport::TAGS, 'Calm tag', ImportReport::CREATED);

        $html = (string) $this->blade('<x-transfer.report :report="$report" />', ['report' => $report]);

        $this->assertLessThan(strpos($html, 'Good one'), strpos($html, 'Bad one'));
        $this->assertSame(1, substr_count($html, 'open: true'));
        $this->assertSame(1, substr_count($html, 'open: false'));
    }

    public function test_the_page_has_its_own_title(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('settings.transfer'))
            ->assertSee(config('app.name').': Import/Export</title>', false);
    }
}
