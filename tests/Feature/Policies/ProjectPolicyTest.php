<?php

namespace Tests\Feature\Policies;

use App\Models\Project;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributors_read_and_only_the_owner_manages(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $stranger = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($reader);

        foreach (['view', 'update', 'delete', 'manageContributors'] as $ability) {
            $this->assertTrue($owner->can($ability, $project), "owner: {$ability}");
            $this->assertFalse($stranger->can($ability, $project), "stranger: {$ability}");
        }

        $this->assertTrue($reader->can('view', $project));
        $this->assertFalse($reader->can('update', $project));
        $this->assertFalse($reader->can('delete', $project));
        $this->assertFalse($reader->can('manageContributors', $project));

        $this->assertTrue($reader->can('leave', $project));
        $this->assertFalse($owner->can('leave', $project));
    }

    public function test_the_scopes_select_what_view_allows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = $user->questions()->create([]);
        $template = $other->questions()->create(['is_template' => true]);
        $other->questions()->create([]);

        $this->assertEqualsCanonicalizing([$own->id, $template->id], Question::visibleTo($user)->pluck('id')->all());

        $mine = Project::factory()->create(['user_id' => $user->id]);
        Project::factory()->create(['user_id' => $other->id]);
        $this->assertSame([$mine->id], Project::accessibleBy($user)->pluck('id')->all());
    }
}
