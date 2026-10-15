<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\DeleteProject;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class DeleteProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_the_remembered_project_forgets_it(): void
    {
        $project = Project::factory()->create();
        request()->cookies->set('curr_project', (string) $project->id);

        app(DeleteProject::class)->handle($project);

        $this->assertModelMissing($project);
        $this->assertTrue(Cookie::hasQueued('curr_project'));
        $this->assertLessThan(time(), Cookie::queued('curr_project')->getExpiresTime());
    }

    public function test_deleting_another_project_keeps_the_remembered_one(): void
    {
        $remembered = Project::factory()->create();
        $other = Project::factory()->create();
        request()->cookies->set('curr_project', (string) $remembered->id);

        app(DeleteProject::class)->handle($other);

        $this->assertModelMissing($other);
        $this->assertFalse(Cookie::hasQueued('curr_project'));
    }
}
