<?php

namespace Tests\Feature;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InitExpertsAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function runOn(array $entries): string
    {
        $path = storage_path('framework/testing-init-experts-attributes.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($entries, JSON_THROW_ON_ERROR));

        try {
            Artisan::call('init:experts', ['--file' => $path]);

            return Artisan::output();
        } finally {
            File::delete($path);
        }
    }

    private function entry(array $extra = []): array
    {
        return $extra + ['name' => 'Alex Brandt', 'roles' => ['status' => 'Teacher'], 'description' => 'Knows things.'];
    }

    /** The expert's attribute values without the Role, which init:experts always writes. */
    private function values(Expert $expert): array
    {
        return Arr::except($this->allValues($expert), [PersonaAttribute::ROLE]);
    }

    private function allValues(Expert $expert): array
    {
        return $expert->personaAttributes()->get()->mapWithKeys(fn ($a) => [$a->name => $a->pivot->value])->all();
    }

    public function test_attributes_are_set_idempotently_and_a_value_already_set_stays(): void
    {
        PersonaAttribute::create(['name' => 'origin', 'self_template' => ':value', 'roster_template' => ':value']);
        $entries = [$this->entry(['attributes' => ['Origin' => 'Lagos']])];

        $this->runOn($entries);
        $this->runOn($entries);

        $this->assertSame(['origin' => 'Lagos'], $this->values(Expert::sole()));

        $this->runOn([$this->entry(['attributes' => ['origin' => 'Berlin']])]);

        $this->assertSame(['origin' => 'Lagos'], $this->values(Expert::sole()));
    }

    public function test_an_unknown_attribute_is_skipped_with_a_warning_and_not_created(): void
    {
        $output = $this->runOn([$this->entry(['attributes' => ['origin' => 'Lagos']])]);

        $this->assertStringContainsString('Attribute does not exist and was skipped: origin: Lagos', $output);
        $this->assertSame([PersonaAttribute::ROLE], PersonaAttribute::pluck('name')->all(), 'only the Role definition is created');
        $this->assertSame([], $this->values(Expert::sole()));
    }

    public function test_a_bad_value_is_skipped_with_a_warning_and_the_run_goes_on(): void
    {
        PersonaAttribute::create(['name' => 'origin', 'self_template' => ':value', 'roster_template' => ':value']);
        PersonaAttribute::create(['name' => 'gender', 'self_template' => ':value', 'roster_template' => ':value']);
        PersonaAttribute::create(['name' => 'age', 'self_template' => ':value', 'roster_template' => ':value']);
        $long = str_repeat('x', PersonaAttribute::VALUE_MAX + 1);

        $output = $this->runOn([
            $this->entry(['attributes' => ['origin' => $long, 'gender' => "wo\nman", 'age' => '31']]),
            $this->entry(['name' => 'Second Person', 'attributes' => ['gender' => 'man']]),
        ]);

        $this->assertStringContainsString('Attribute value too long (over '.PersonaAttribute::VALUE_MAX.' characters) and was skipped: origin: ', $output);
        $this->assertStringContainsString('Attribute value contains a line break and was skipped: gender: ', $output);
        $this->assertSame(2, Expert::count());
        $this->assertSame(['age' => '31'], $this->values(Expert::where('name', 'Alex Brandt')->sole()));
        $this->assertSame(['gender' => 'man'], $this->values(Expert::where('name', 'Second Person')->sole()));
    }

    public function test_an_empty_value_is_skipped_without_a_warning(): void
    {
        PersonaAttribute::create(['name' => 'origin', 'self_template' => ':value', 'roster_template' => ':value']);

        $output = $this->runOn([$this->entry(['attributes' => ['origin' => '']])]);

        $this->assertStringNotContainsString('Attribute', $output);
        $this->assertSame([], $this->values(Expert::sole()));
    }

    public function test_entries_without_attributes_work_as_before(): void
    {
        PersonaAttribute::create(['name' => 'origin', 'self_template' => ':value', 'roster_template' => ':value']);

        $output = $this->runOn([$this->entry()]);

        $this->assertStringContainsString('1 created', $output);
        $this->assertStringNotContainsString('Attribute does not exist', $output);
        $this->assertSame([], $this->values(Expert::sole()));
    }

    public function test_the_role_is_written_as_the_role_attribute_and_the_definition_is_created_once(): void
    {
        $this->assertSame(0, PersonaAttribute::count());
        $entries = [$this->entry(['roles' => ['status' => 'Teacher', 'equal' => 'Student']])];

        $this->runOn($entries);
        $this->runOn($entries);

        $this->assertSame(1, PersonaAttribute::where('name', PersonaAttribute::ROLE)->count());
        $this->assertSame(2, Expert::count());
        $this->assertSame(['Student', 'Teacher'], Expert::all()->map(fn ($e) => $this->allValues($e)[PersonaAttribute::ROLE])->sort()->values()->all());
    }

    public function test_an_existing_role_definition_is_reused_ignoring_case(): void
    {
        $existing = PersonaAttribute::create(['name' => 'role', 'self_template' => 'You are :value.', 'roster_template' => ':value']);

        $this->runOn([$this->entry()]);

        $this->assertSame([$existing->id], PersonaAttribute::pluck('id')->all());
        $this->assertSame('Teacher', $this->allValues(Expert::sole())['role']);
    }
}
