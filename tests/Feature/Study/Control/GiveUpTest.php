<?php

namespace Tests\Feature\Study\Control;

use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Study\Control\GiveUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiveUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_creates_the_tag_once_and_sets_it(): void
    {
        $giveUp = app(GiveUp::class);
        [$first, $second, $open] = Project::factory()->count(3)->create()->all();

        $giveUp->mark($first);
        $giveUp->mark($second);
        $giveUp->mark($second);

        $this->assertSame(1, TagCategory::where('name', 'superseded')->count());
        $this->assertSame('true', $first->tagValues()->sole()->name);
        $this->assertTrue($giveUp->isMarked($second));
        $this->assertFalse($giveUp->isMarked($open));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $giveUp->markedAmong([$first->id, $second->id, $open->id]));
    }

    /** Settings → Tags may have created the category by hand, spelled its own way. */
    public function test_it_reuses_a_category_named_alike(): void
    {
        TagCategory::create(['scope' => TagScope::Project, 'name' => ' Superseded ']);
        $project = Project::factory()->create();

        app(GiveUp::class)->mark($project);

        $this->assertSame(1, TagCategory::count());
        $this->assertTrue(app(GiveUp::class)->isMarked($project));
    }

    public function test_nothing_is_marked_before_the_category_exists(): void
    {
        $this->assertSame([], app(GiveUp::class)->markedAmong([1, 2]));
    }
}
