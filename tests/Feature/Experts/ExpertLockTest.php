<?php

namespace Tests\Feature\Experts;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\PersonaAttribute;
use App\Models\PersonaLocked;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a started run read of a persona cannot change any more: name,
 * description, attribute values, its existence. Tags and avatar reach no model.
 */
class ExpertLockTest extends TestCase
{
    use RefreshDatabase;

    private function lockedExpert(): Expert
    {
        $expert = Expert::factory()->withRole('Teacher')->create(['name' => 'Ada', 'description' => 'Kennt Schulen.']);
        $project = Project::factory()->create(['run_config' => ['pipeline' => 'RoundRobinPipeline']]);
        $project->addContributingExpert($expert);

        return $expert->fresh();
    }

    private function assertLocked(callable $change): void
    {
        try {
            $change();
            $this->fail('A locked persona must refuse this change.');
        } catch (PersonaLocked) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_an_expert_is_locked_once_seated_in_a_started_run_or_named_by_a_turn(): void
    {
        $free = Expert::factory()->create();
        Project::factory()->create()->addContributingExpert($free);
        $this->assertFalse($free->isLocked(), 'a run that has not started locks nothing');

        $this->assertTrue($this->lockedExpert()->isLocked());

        $leaver = Expert::factory()->create();
        JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'expert_id' => $leaver->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1]);
        $this->assertTrue($leaver->isLocked(), 'who spoke and left the run stays locked');
    }

    public function test_the_answer_is_read_once_per_instance_and_a_fresh_instance_sees_a_new_lock(): void
    {
        $expert = Expert::factory()->create();
        Project::factory()->create()->addContributingExpert($expert);

        DB::enableQueryLog();
        $expert->isLocked();
        $expert->isLocked();
        $this->assertCount(2, DB::getQueryLog(), 'one lookup of two queries, then memoised');

        JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'expert_id' => $expert->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1]);
        $this->assertTrue(Expert::find($expert->id)->isLocked());
    }

    public function test_name_description_attributes_and_deletion_are_refused(): void
    {
        $expert = $this->lockedExpert();

        $this->assertLocked(fn () => $expert->update(['name' => 'Bea']));
        $this->assertLocked(fn () => $expert->fresh()->update(['description' => 'Anders.']));
        $this->assertLocked(fn () => $expert->setAttributeValue(PersonaAttribute::role(), 'Student'));
        $this->assertLocked(fn () => $expert->syncAttributeValues([PersonaAttribute::role()->id => 'Student']));
        $this->assertLocked(fn () => $expert->delete());

        $expert = $expert->fresh();
        $this->assertSame(['Ada', 'Kennt Schulen.'], [$expert->name, $expert->description]);
        $this->assertSame('Teacher', $expert->personaAttributes->first()->pivot->value);
    }

    public function test_tags_and_avatar_stay_changeable_and_deletion_keeps_the_tags(): void
    {
        $expert = $this->lockedExpert();
        $category = TagCategory::create(['name' => 'focus persona', 'scope' => TagScope::Persona]);
        $value = $category->values()->create(['name' => 'true']);

        $expert->setTag($category, $value);
        $expert->update(['avatar_url' => '/a.png']);

        $this->assertLocked(fn () => $expert->delete());
        $this->assertSame([$category->id => $value->id], $expert->fresh()->tagSelection());
        $this->assertSame('/a.png', $expert->fresh()->avatar_url);
    }

    public function test_the_policy_refuses_admins_update_and_delete_but_lets_them_change_tags(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $locked = $this->lockedExpert();
        $free = Expert::factory()->create();

        $this->assertFalse($admin->can('update', $locked));
        $this->assertFalse($admin->can('delete', $locked));
        $this->assertTrue($admin->can('updateTags', $locked));
        $this->assertTrue($admin->can('update', $free));
        $this->assertFalse(User::factory()->create()->can('updateTags', $locked));
    }

    public function test_the_editor_opens_read_only_with_the_reason_and_saves_tags_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $expert = $this->lockedExpert();
        $category = TagCategory::create(['name' => 'focus persona', 'scope' => TagScope::Persona]);
        $value = $category->values()->create(['name' => 'true']);

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSee(__('experts.editor.locked'))
            ->assertDontSee(__('experts.editor.delete'))
            ->set('name', 'Gekapert')
            ->call('save')
            ->assertForbidden();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set('name', 'Gekapert')
            ->set("tagSelection.{$category->id}", (string) $value->id)
            ->call('saveTags')
            ->assertHasNoErrors();

        $this->assertSame('Ada', $expert->fresh()->name);
        $this->assertSame([$category->id => $value->id], $expert->fresh()->tagSelection());
    }

    public function test_init_experts_skips_a_locked_expert_with_a_warning(): void
    {
        $this->lockedExpert();
        $path = storage_path('framework/testing-init-experts-lock.json');
        File::put($path, json_encode([['name' => 'Ada', 'avatar_url' => '', 'roles' => ['Teacher', 'Student'], 'description' => 'Neu.']]));

        try {
            $this->assertSame(0, Artisan::call('init:experts', ['--file' => $path]));
        } finally {
            File::delete($path);
        }

        $this->assertStringContainsString('locked', Artisan::output());
        $this->assertSame('Kennt Schulen.', Expert::where('name', 'Ada')->get()->firstWhere(fn (Expert $e) => $e->isLocked())->description);
        $this->assertSame(2, Expert::where('name', 'Ada')->count(), 'the unlocked role is still created');
    }
}
