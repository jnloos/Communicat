<?php

namespace Tests\Unit\Tags;

use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class HasTagsTest extends TestCase
{
    use RefreshDatabase;

    private function category(TagScope $scope, string $name, array $values): TagCategory
    {
        $category = TagCategory::create(['scope' => $scope, 'name' => $name]);

        foreach ($values as $value) {
            $category->values()->create(['name' => $value]);
        }

        return $category->load('values');
    }

    private function value(TagCategory $category, string $name): TagValue
    {
        return $category->values->firstWhere('name', $name);
    }

    public function test_a_second_value_of_the_same_category_replaces_the_first(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich', 'männlich']);
        $expert = Expert::factory()->create();

        $expert->setTag($gender, $this->value($gender, 'weiblich'));
        $expert->setTag($gender, $this->value($gender, 'männlich'));

        $this->assertSame(1, Taggable::count());
        $this->assertSame('männlich', $expert->tagFor($gender)?->name);
        $this->assertSame((new Expert)->getMorphClass(), Taggable::sole()->taggable_type);
    }

    public function test_null_clears_the_category(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich']);
        $expert = Expert::factory()->create();

        $expert->setTag($gender, $this->value($gender, 'weiblich'));
        $expert->setTag($gender, null);

        $this->assertNull($expert->tagFor($gender));
        $this->assertSame(0, Taggable::count());
    }

    public function test_a_value_of_another_category_is_refused(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich']);
        $group = $this->category(TagScope::Persona, 'Gruppe', ['Mehrheit']);

        $this->expectException(InvalidArgumentException::class);

        Expert::factory()->create()->setTag($gender, $this->value($group, 'Mehrheit'));
    }

    public function test_a_category_of_the_other_scope_is_refused(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status']);

        $this->expectException(InvalidArgumentException::class);

        Expert::factory()->create()->setTag($condition, $this->value($condition, 'status'));
    }

    public function test_tag_selection_and_sync_tags_round_trip_a_form(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status', 'kontrolle']);
        $topic = $this->category(TagScope::Project, 'thema', ['Schule']);
        $project = Project::factory()->create();

        $project->syncTags([
            $condition->id => (string) $this->value($condition, 'kontrolle')->id,
            $topic->id => '',
        ]);

        $this->assertSame([$condition->id => $this->value($condition, 'kontrolle')->id], $project->tagSelection());
    }

    public function test_sync_tags_refuses_a_value_outside_its_category(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status']);
        $topic = $this->category(TagScope::Project, 'thema', ['Schule']);

        $this->expectException(InvalidArgumentException::class);

        Project::factory()->create()->syncTags([$condition->id => $this->value($topic, 'Schule')->id]);
    }

    public function test_where_tagged_is_and_across_categories_and_or_within_one(): void
    {
        $condition = $this->category(TagScope::Project, 'bedingung', ['status', 'kontrolle']);
        $topic = $this->category(TagScope::Project, 'thema', ['Schule', 'Verkehr']);

        $schoolStatus = Project::factory()->create();
        $schoolStatus->setTag($condition, $this->value($condition, 'status'));
        $schoolStatus->setTag($topic, $this->value($topic, 'Schule'));

        $trafficControl = Project::factory()->create();
        $trafficControl->setTag($condition, $this->value($condition, 'kontrolle'));
        $trafficControl->setTag($topic, $this->value($topic, 'Verkehr'));

        $untagged = Project::factory()->create();

        $or = Project::whereTagged([$this->value($condition, 'status')->id, $this->value($condition, 'kontrolle')->id])->pluck('id')->all();
        $and = Project::whereTagged([$this->value($condition, 'status')->id, $this->value($topic, 'Verkehr')->id])->pluck('id')->all();
        $none = Project::whereTagged([])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$schoolStatus->id, $trafficControl->id], $or);
        $this->assertSame([], $and);
        $this->assertEqualsCanonicalizing([$schoolStatus->id, $trafficControl->id, $untagged->id], $none);
    }

    public function test_deleting_a_value_or_a_category_removes_their_assignments(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich', 'männlich']);
        $group = $this->category(TagScope::Persona, 'Gruppe', ['Mehrheit']);
        $expert = Expert::factory()->create();
        $expert->setTag($gender, $this->value($gender, 'weiblich'));
        $expert->setTag($group, $this->value($group, 'Mehrheit'));

        $this->value($gender, 'weiblich')->delete();
        $this->assertSame(1, Taggable::count());

        $group->delete();
        $this->assertSame(0, Taggable::count());
        $this->assertSame(0, TagValue::where('tag_category_id', $group->id)->count());
    }

    public function test_deleting_a_tagged_model_removes_its_assignments(): void
    {
        $gender = $this->category(TagScope::Persona, 'Geschlecht', ['weiblich']);
        $expert = Expert::factory()->create();
        $expert->setTag($gender, $this->value($gender, 'weiblich'));

        $expert->delete();

        $this->assertSame(0, Taggable::count());
    }

    public function test_new_rows_go_to_the_end_and_move_to_renumbers(): void
    {
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        $b = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);
        $c = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'C']);
        $other = TagCategory::create(['scope' => TagScope::Project, 'name' => 'X']);

        $this->assertSame([1, 2, 3], [$a->position, $b->position, $c->position]);
        $this->assertSame(1, $other->position);

        $c->moveTo(0);

        $this->assertSame(['C', 'A', 'B'], TagCategory::ofScope(TagScope::Persona)->pluck('name')->all());
    }
}
