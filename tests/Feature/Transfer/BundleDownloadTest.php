<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectTransfer\ProjectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundleDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_downloads_for_whoever_may_access_it(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'School']);

        $this->actingAs($owner)->get(route('project.export', $project))
            ->assertOk()
            ->assertDownload('communicat-project-school-'.now()->format('Y-m-d').'.zip');
        $this->actingAs(User::factory()->create())->get(route('project.export', $project))->assertForbidden();
    }

    public function test_an_expert_downloads_for_every_signed_in_user(): void
    {
        $expert = Expert::factory()->create(['name' => 'Alex Brandt']);

        $this->actingAs(User::factory()->create())->get(route('experts.export', $expert))->assertOk()->assertDownload();
    }

    public function test_everything_downloads_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.export'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('settings.export'))->assertOk()->assertDownload();
    }

    public function test_the_old_export_and_upload_are_gone(): void
    {
        $this->assertFalse(app('router')->has('project.export.json'));
        $this->assertFalse(class_exists(ProjectImporter::class));
    }
}
