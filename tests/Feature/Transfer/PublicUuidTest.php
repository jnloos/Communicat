<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    public function test_experts_and_projects_get_a_uuid_when_created(): void
    {
        $expert = Expert::factory()->create();
        $project = Project::factory()->create();

        $this->assertTrue(Str::isUuid($expert->uuid));
        $this->assertTrue(Str::isUuid($project->uuid));
        $this->assertNotSame($expert->uuid, Expert::factory()->create()->uuid);
    }

    public function test_a_given_uuid_is_kept(): void
    {
        $uuid = (string) Str::uuid();
        $expert = Expert::factory()->create(['uuid' => $uuid]);

        $this->assertSame($uuid, $expert->fresh()->uuid);
    }
}
