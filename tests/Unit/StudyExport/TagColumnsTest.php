<?php

namespace Tests\Unit\StudyExport;

use App\Models\TagCategory;
use App\Models\TagValue;
use App\Services\StudyExport\TagColumns;
use Tests\TestCase;

/** How tag categories become CSV headers that never overwrite each other. */
class TagColumnsTest extends TestCase
{
    private function category(int $id, string $name): TagCategory
    {
        return (new TagCategory(['name' => $name]))->forceFill(['id' => $id]);
    }

    private function value(int $categoryId, string $name): TagValue
    {
        return (new TagValue)->forceFill(['tag_category_id' => $categoryId, 'name' => $name]);
    }

    public function test_a_category_takes_its_own_name(): void
    {
        $columns = new TagColumns(collect([$this->category(1, 'status condition'), $this->category(2, 'Seat')]), ['run_uuid']);

        $this->assertSame(['status condition', 'Seat'], $columns->headers());
    }

    public function test_a_category_named_like_a_fixed_column_gets_the_tag_prefix_ignoring_case_and_space(): void
    {
        $columns = new TagColumns(collect([$this->category(1, ' Group '), $this->category(2, 'TURNS')]), ['group', 'turns']);

        $this->assertSame(['tag  Group ', 'tag TURNS'], $columns->headers());
    }

    public function test_a_category_named_like_an_earlier_one_gets_its_id(): void
    {
        $columns = new TagColumns(collect([$this->category(1, 'Status'), $this->category(7, ' status ')]), []);

        $this->assertSame(['Status', 'status #7'], $columns->headers());
    }

    public function test_a_prefixed_name_that_is_taken_falls_back_to_the_id(): void
    {
        $columns = new TagColumns(collect([$this->category(1, 'tag group'), $this->category(2, 'group')]), ['group']);

        $this->assertSame(['tag group', 'group #2'], $columns->headers());
    }

    public function test_cells_name_the_value_per_column_and_leave_gaps_empty(): void
    {
        $columns = new TagColumns(collect([$this->category(1, 'Status'), $this->category(2, '1'), $this->category(3, 'Seat')]), []);

        $cells = $columns->cells(collect([$this->value(3, 'one'), $this->value(2, 'numeric')]));

        $this->assertSame(['Status' => null, '1' => 'numeric', 'Seat' => 'one'], $cells);
    }
}
