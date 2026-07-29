<?php

namespace Tests\Feature\Onboarding;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\Onboarding\DemoDiscussionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DemoDiscussionSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The four personas referenced by database/demo-discussion.json. */
    private const DEMO_NAMES = ['Michael Bauer', 'Sophie Wagner', 'Lena Fischer', 'Jan Lehmann'];

    private function seedExperts(array $names = self::DEMO_NAMES): void
    {
        foreach ($names as $name) {
            Expert::factory()->create(['name' => $name]);
        }
    }

    public function test_seeds_demo_by_name_with_all_pair_types(): void
    {
        $this->seedExperts();
        $owner = User::factory()->create();

        $seeder = new DemoDiscussionSeeder;
        $project = $seeder->seed($owner);

        $this->assertTrue($project->isDemo());
        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame(4, $project->experts()->count());
        $this->assertSame(9, $project->messages()->count());
        $this->assertSame(4, $project->summaries()->count());
        $this->assertEmpty($seeder->missingExperts());

        // All five adjacency-pair types are demonstrated.
        $pairTypes = $project->messages()->whereNotNull('adjacency_pair_type')
            ->pluck('adjacency_pair_type')->unique()->values()->all();
        $this->assertEqualsCanonicalizing([
            Message::PAIR_FRAGE_ANTWORT,
            Message::PAIR_ANSPRACHE_REAKTION,
            Message::PAIR_BEITRAG_DISKUSSION,
            Message::PAIR_SYNTHESE_DISKUSSION,
            Message::PAIR_ABSCHLUSS_NUTZER,
        ], $pairTypes);

        // At least one turn hands back to the user (arrow points at the human).
        $this->assertTrue(
            $project->messages()->where('adjacency_partner_type', User::class)->exists(),
        );

        // The owner name is substituted into the memory (%OWNER% placeholder).
        $this->assertStringContainsString(
            $owner->name,
            $project->summaries()->first()->content,
        );
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seedExperts();
        $owner = User::factory()->create();

        $seeder = new DemoDiscussionSeeder;
        $first = $seeder->seed($owner);
        $second = $seeder->seed($owner);

        $this->assertSame($first->id, $second->id, 'Re-seeding must reuse the single demo project.');
        $this->assertSame(1, Project::query()->where('settings->is_demo', true)->count());
        $this->assertSame(9, $second->messages()->count(), 'Messages must be replaced, not duplicated.');
    }

    public function test_demo_is_viewable_by_any_authenticated_user(): void
    {
        $this->seedExperts();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $demo = (new DemoDiscussionSeeder)->seed($owner);

        $this->assertFalse($demo->hasContributor($stranger));
        $this->assertTrue(Gate::forUser($stranger)->allows('access-project', $demo));
    }

    public function test_missing_experts_are_reported(): void
    {
        // Seed only three of the four demo personas.
        $this->seedExperts(['Michael Bauer', 'Sophie Wagner', 'Lena Fischer']);
        $owner = User::factory()->create();

        $seeder = new DemoDiscussionSeeder;
        $seeder->seed($owner);

        $this->assertContains('Jan Lehmann', $seeder->missingExperts());
    }
}
