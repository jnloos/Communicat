<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\QuestionNormaliser;
use App\Discussion\Values\QuestionDefinition;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionNormaliserTest extends TestCase
{
    use RefreshDatabase;

    private function normaliser(): QuestionNormaliser
    {
        return app(QuestionNormaliser::class);
    }

    /** A run of the user with this many spoken turns. */
    private function runWithTurns(User $user, int $turns): void
    {
        $project = Project::factory()->create(['user_id' => $user->id]);
        $expert = Expert::factory()->create();
        $project->addContributingExpert($expert);

        foreach (range(1, $turns) as $turn) {
            JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => $turn, 'expert_id' => $expert->id, 'words' => 10]);
        }
    }

    public function test_a_comparison_without_baseline_gets_the_first_value_of_its_dimension(): void
    {
        $user = User::factory()->create();
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status']);
        $first = $category->values()->create(['name' => 'with']);
        $category->values()->create(['name' => 'without']);

        $definition = $this->normaliser()->withBaseline($user, QuestionDefinition::fromArray(['compare_by' => "project_tag:{$category->id}"]));

        $this->assertSame((string) $first->id, $definition->baseline);
    }

    public function test_a_chosen_baseline_and_a_question_without_comparison_stay_as_they_are(): void
    {
        $user = User::factory()->create();
        $chosen = QuestionDefinition::fromArray(['compare_by' => 'pipeline', 'baseline' => 'QualityPipeline']);
        $uncompared = new QuestionDefinition;

        $this->assertSame('QualityPipeline', $this->normaliser()->withBaseline($user, $chosen)->baseline);
        $this->assertNull($this->normaliser()->withBaseline($user, $uncompared)->baseline);
    }

    public function test_the_group_baseline_is_the_viewers_first_group(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();
        $other->projectGroups()->create(['name' => 'Not mine', 'position' => 0]);
        $mine = $viewer->projectGroups()->create(['name' => 'Mine', 'position' => 0]);

        $definition = $this->normaliser()->withBaseline($viewer, QuestionDefinition::fromArray(['compare_by' => 'group']));

        $this->assertSame((string) $mine->id, $definition->baseline);
    }

    public function test_a_persona_tag_without_values_falls_back_to_no_value(): void
    {
        $user = User::factory()->create();
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'empty']);

        $definition = $this->normaliser()->withBaseline($user, QuestionDefinition::fromArray(['compare_by' => "persona_tag:{$category->id}"]));

        $this->assertSame(DimensionValues::NONE, $definition->baseline);
    }

    public function test_deleted_tag_values_are_dropped_and_counted_but_a_category_never_empties(): void
    {
        $persona = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'role']);
        $kept = $persona->values()->create(['name' => 'kept']);
        $gone = $persona->values()->create(['name' => 'gone']);
        $project = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status']);
        $alone = $project->values()->create(['name' => 'alone']);
        $definition = QuestionDefinition::fromArray([
            'people' => ['tags' => [$persona->id => [$kept->id, $gone->id]]],
            'projects' => ['tags' => [$project->id => [$alone->id]]],
        ]);
        $gone->delete();
        $alone->delete();

        ['definition' => $cleaned, 'dropped' => $dropped] = $this->normaliser()->withoutDeletedTagValues($definition);

        $this->assertSame(2, $dropped);
        $this->assertSame([$kept->id], $cleaned->people->tags->valueIds());
        // All its values gone, the category keeps them, so the filter matches nothing instead of everything.
        $this->assertSame([$alone->id], $cleaned->projects->tags->valueIds());
    }

    public function test_a_definition_naming_only_existing_values_comes_back_unchanged(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'role']);
        $value = $category->values()->create(['name' => 'kept']);
        $definition = QuestionDefinition::fromArray(['people' => ['tags' => [$category->id => [$value->id]]]]);

        $this->assertSame(['definition' => $definition, 'dropped' => 0], $this->normaliser()->withoutDeletedTagValues($definition));
        $this->assertSame(0, $this->normaliser()->withoutDeletedTagValues(new QuestionDefinition)['dropped']);
    }
}
