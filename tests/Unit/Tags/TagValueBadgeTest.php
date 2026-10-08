<?php

namespace Tests\Unit\Tags;

use App\Models\TagCategory;
use App\Models\TagValue;
use PHPUnit\Framework\TestCase;

class TagValueBadgeTest extends TestCase
{
    private function value(string $name, string $category = 'title-holder'): TagValue
    {
        $value = new TagValue(['name' => $name]);
        $value->setRelation('category', new TagCategory(['name' => $category]));

        return $value;
    }

    public function test_a_badge_always_names_category_and_value(): void
    {
        $this->assertSame('Status condition: status', $this->value('status', 'status condition')->fullLabel());
        $this->assertSame('Title-holder: true', $this->value('true')->fullLabel());
        $this->assertSame('Title-holder: false', $this->value('false')->fullLabel());
    }

    public function test_a_category_label_capitalises_for_display_only(): void
    {
        $category = new TagCategory(['name' => 'title-holder']);

        $this->assertSame('Title-holder', $category->label());
        $this->assertSame('title-holder', $category->name);
        $this->assertSame('Ärger', TagCategory::labelFor('ärger'));
        $this->assertSame('Status condition', TagCategory::labelFor('Status condition'));
    }
}
