<?php

namespace Tests\Feature\Transfer;

use App\Livewire\Settings\Transfer;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\BundleExporter;
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
        $path = tempnam(sys_get_temp_dir(), 'bundle');
        file_put_contents($path, 'nope');

        Livewire::test(Transfer::class)
            ->set('bundle', UploadedFile::fake()->createWithContent('x.zip', 'nope'))
            ->assertSet('stage', 'upload')
            ->assertHasErrors('bundle');
    }

    public function test_a_non_admin_cannot_import_even_by_calling_the_action(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Transfer::class)->assertForbidden();
    }
}
