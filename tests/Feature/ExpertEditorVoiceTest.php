<?php

namespace Tests\Feature;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertEditorVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_gender_is_resolved_for_existing_female_voice(): void
    {
        $female = config('voices.female.0.id');
        $this->assertNotEmpty($female, 'config/voices.php must seed at least one female voice');

        $expert = Expert::factory()->create(['voice_id' => $female]);
        $admin = User::factory()->create();

        $this->actingAs($admin);
        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet('voiceId', $female)
            ->assertSet('voiceGender', 'female');
    }

    public function test_voice_can_be_changed_and_saved(): void
    {
        $male = config('voices.male.0.id');
        $expert = Expert::factory()->create(['voice_id' => null]);
        $admin = User::factory()->create();

        $this->actingAs($admin);
        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set('voiceGender', 'male')
            ->set('voiceId', $male)
            ->call('save');

        $this->assertSame($male, $expert->fresh()->voice_id);
    }

    public function test_switching_gender_clears_voice_when_mismatched(): void
    {
        $female = config('voices.female.0.id');
        $expert = Expert::factory()->create(['voice_id' => $female]);
        $admin = User::factory()->create();

        $this->actingAs($admin);
        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set('voiceGender', 'male')
            ->assertSet('voiceId', '');
    }

    public function test_unknown_voice_id_is_preserved_in_dropdown(): void
    {
        $unknown = 'totally-not-in-the-catalog';
        $expert = Expert::factory()->create(['voice_id' => $unknown]);
        $admin = User::factory()->create();

        $this->actingAs($admin);
        $component = Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id);

        $this->assertSame($unknown, $component->get('voiceId'));
    }
}
