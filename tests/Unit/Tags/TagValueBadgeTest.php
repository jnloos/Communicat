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

    public function test_a_badge_always_names_category_and_value(): void
    {
        $this->assertSame('condition: status', $this->value('status', 'condition')->fullLabel());
        $this->assertSame('title holder: true', $this->value('true')->fullLabel());
        $this->assertSame('title holder: false', $this->value('false')->fullLabel());
    }
}
