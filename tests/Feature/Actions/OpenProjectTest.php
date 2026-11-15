<?php

namespace Tests\Feature\Actions;

use App\Actions\Projects\OpenProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_given_uuid_is_kept(): void
    {
        $uuid = '0b7f6c2e-3d1a-4f5e-9a8b-1c2d3e4f5a6b';

        $project = app(OpenProject::class)->handle(User::factory()->create(), ['title' => 'T', 'description' => 'D'], uuid: $uuid);

        $this->assertSame($uuid, $project->fresh()->uuid);
    }

    public function test_without_a_uuid_one_is_generated(): void
    {
        $project = app(OpenProject::class)->handle(User::factory()->create(), ['title' => 'T', 'description' => 'D']);

        $this->assertNotEmpty($project->fresh()->uuid);
    }
}
