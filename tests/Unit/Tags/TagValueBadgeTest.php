<?php

namespace Tests\Unit\Tags;

use App\Models\TagCategory;
use App\Models\TagValue;
use PHPUnit\Framework\TestCase;

class TagValueBadgeTest extends TestCase
{
    private function value(string $name, string $category = 'title holder'): TagValue
    {
        $value = new TagValue(['name' => $name]);
        $value->setRelation('category', new TagCategory(['name' => $category]));

        return $value;
    }

    public function test_an_ordinary_value_shows_itself(): void
    {
        $this->assertSame('status', $this->value('status', 'condition')->badgeLabel());
    }

    public function test_true_shows_the_category_and_false_shows_nothing(): void
    {
        $this->assertSame('title holder', $this->value('true')->badgeLabel());
        $this->assertSame('title holder', $this->value('True')->badgeLabel());
        $this->assertNull($this->value('false')->badgeLabel());
        $this->assertNull($this->value(' FALSE ')->badgeLabel());
    }

    public function test_the_tooltip_names_category_and_value(): void
    {
        $this->assertSame('title holder: true', $this->value('true')->fullLabel());
    }
}
