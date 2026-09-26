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
     * One entry is one knowledge profile and becomes two personas: the same
     * description under the scenario's high-status label and under its peer
     * label. That is what lets a run swap the title without changing anything
     * else about the agent.
     */
    public function test_one_entry_becomes_one_persona_per_role(): void
    {
        $this->assertSame(0, $this->runOn([[
            'scenario' => 'school',
            'name' => 'Alex Brandt',
            'avatar_url' => '',
            'roles' => ['lead' => 'Teacher', 'peer' => 'Student'],
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
            'roles' => ['lead' => 'Teacher', 'peer' => 'Student'],
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
            'roles' => ['lead' => 'Teacher', 'peer' => 'Student'],
            'description' => 'Fresh.',
        ]]);

        $this->assertSame('Fresh.', Expert::where(['name' => 'Alex Brandt', 'role' => 'Teacher'])->sole()->description);
        $this->assertSame('Fresh.', Expert::where(['name' => 'Alex Brandt', 'role' => 'Student'])->sole()->description);
    }

    /** The shipped catalogue must stay importable, and paired throughout. */
    public function test_the_shipped_catalogue_imports_in_pairs(): void
    {
        $this->assertSame(0, Artisan::call('init:experts'));

        $byName = Expert::all()->groupBy('name');

        $this->assertNotEmpty($byName);

        foreach ($byName as $name => $personas) {
            $this->assertCount(2, $personas, "{$name} must exist under exactly two roles");
            $this->assertCount(1, $personas->pluck('description')->unique(), "{$name}'s descriptions must be identical");
        }
    }
}
