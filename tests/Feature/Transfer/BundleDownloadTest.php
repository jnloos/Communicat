<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BundleDownloadTest extends TestCase
{
    use RefreshDatabase;

    /** The test client never sends the response, so the temp file is still there to read. */
    private function kindOf(TestResponse $response): string
    {
        return BundleReader::open($response->baseResponse->getFile()->getPathname())->manifest()['kind'];
    }

    public function test_a_project_downloads_for_whoever_may_access_it(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'School']);

        $response = $this->actingAs($owner)->get(route('project.export', $project))
            ->assertOk()
            ->assertDownload('communicat-project-school-'.now()->format('Y-m-d').'.zip');
        $this->assertSame(Bundle::KIND_PROJECT, $this->kindOf($response));
        $this->actingAs(User::factory()->create())->get(route('project.export', $project))->assertForbidden();
    }

    public function test_an_expert_downloads_for_every_signed_in_user(): void
    {
        $expert = Expert::factory()->create(['name' => 'Alex Brandt']);

        $response = $this->actingAs(User::factory()->create())->get(route('experts.export', $expert))->assertOk()->assertDownload();
        $this->assertSame(Bundle::KIND_EXPERT, $this->kindOf($response));
    }

    public function test_everything_downloads_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.export'))->assertForbidden();
        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('settings.export'))->assertOk()->assertDownload();
        $this->assertSame(Bundle::KIND_GLOBAL, $this->kindOf($response));
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $project = Project::factory()->create();
        $expert = Expert::factory()->create();

        $this->get(route('project.export', $project))->assertRedirect(route('login'));
        $this->get(route('experts.export', $expert))->assertRedirect(route('login'));
        $this->get(route('settings.export'))->assertRedirect(route('login'));
    }

    public function test_the_old_export_and_upload_are_gone(): void
    {
        $this->assertFalse(app('router')->has('project.export.json'));
        $this->assertFalse(class_exists(implode('\\', ['App', 'Services', 'ProjectTransfer', 'ProjectImporter'])));
    }
}
