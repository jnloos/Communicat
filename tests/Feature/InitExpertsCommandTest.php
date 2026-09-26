<?php

namespace Tests\Feature;

use App\Models\Expert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InitExpertsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array<string, mixed>>  $entries */
    private function runOn(array $entries): int
    {
        $path = storage_path('framework/testing-init-experts.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($entries, JSON_THROW_ON_ERROR));

        try {
            return Artisan::call('init:experts', ['--file' => $path]);
        } finally {
            File::delete($path);
        }
    }

    /**
     * One entry is one knowledge profile and becomes one persona per role label:
     * the same description under the label it wears in the status condition and
     * under the one it wears in the equal condition. That is what lets a run swap
     * the title without changing anything else about the agent.
     */
    public function test_one_entry_becomes_one_persona_per_role(): void
    {
        $this->assertSame(0, $this->runOn([[
            'scenario' => 'school',
            'name' => 'Alex Brandt',
            'avatar_url' => '',
            'roles' => ['status' => 'Teacher', 'equal' => 'Student'],
            'description' => 'Knows how attention works.',
        ]]));

        $personas = Expert::where('name', 'Alex Brandt')->orderBy('role')->get();

        $this->assertSame(['Student', 'Teacher'], $personas->pluck('role')->all());
        $this->assertSame(
            ['Knows how attention works.', 'Knows how attention works.'],
            $personas->pluck('description')->all(),
            'both personas must carry the identical description',
        );
    }

    public function test_a_second_run_updates_instead_of_duplicating(): void
    {
        $entry = [
            'scenario' => 'school',
            'name' => 'Alex Brandt',
            'avatar_url' => '',
            'roles' => ['status' => 'Teacher', 'equal' => 'Student'],
            'description' => 'First description.',
        ];

        $this->runOn([$entry]);
        $this->runOn([['description' => 'Second description.'] + $entry]);

        $personas = Expert::where('name', 'Alex Brandt')->get();

        $this->assertCount(2, $personas, 'the command is idempotent');
        $this->assertSame(['Second description.', 'Second description.'], $personas->pluck('description')->all());
    }

    /** A name alone is not the key: the same person exists under two roles. */
    public function test_it_keys_on_name_and_role_together(): void
    {
        Expert::factory()->create(['name' => 'Alex Brandt', 'role' => 'Teacher', 'description' => 'Stale.']);

        $this->runOn([[
            'scenario' => 'school',
            'name' => 'Alex Brandt',
            'avatar_url' => '',
            'roles' => ['status' => 'Teacher', 'equal' => 'Student'],
            'description' => 'Fresh.',
        ]]);

        $this->assertSame('Fresh.', Expert::where(['name' => 'Alex Brandt', 'role' => 'Teacher'])->sole()->description);
        $this->assertSame('Fresh.', Expert::where(['name' => 'Alex Brandt', 'role' => 'Student'])->sole()->description);
    }

    /**
     * A profile that wears the same label in both conditions -- a rank-and-file
     * seat in a graded scenario such as the office -- is one persona, not two.
     */
    public function test_a_profile_with_one_label_for_both_conditions_becomes_one_persona(): void
    {
        $this->assertSame(0, $this->runOn([[
            'scenario' => 'office',
            'name' => 'Noor Kessler',
            'avatar_url' => '',
            'roles' => ['status' => 'Employee', 'equal' => 'Employee'],
            'description' => 'Knows what getting here costs.',
        ]]));

        $this->assertSame(['Employee'], Expert::where('name', 'Noor Kessler')->pluck('role')->all());
    }

    /**
     * The shipped catalogue must stay importable, and every profile must carry
     * exactly the labels it is configured with -- no stale persona left behind
     * when a scenario's roles change.
     */
    public function test_the_shipped_catalogue_imports_every_configured_label(): void
    {
        $this->assertSame(0, Artisan::call('init:experts'));

        $configured = [];

        foreach (json_decode(File::get(base_path('database/experts.json')), true) as $entry) {
            $configured[$entry['name']] = array_values(array_unique($entry['roles']));
            sort($configured[$entry['name']]);
        }

        $this->assertNotEmpty($configured);

        foreach (Expert::all()->groupBy('name') as $name => $personas) {
            $roles = $personas->pluck('role')->sort()->values()->all();

            $this->assertSame($configured[$name] ?? [], $roles, "{$name} must carry exactly its configured labels");
            $this->assertCount(1, $personas->pluck('description')->unique(), "{$name}'s descriptions must be identical");
        }
    }
}
