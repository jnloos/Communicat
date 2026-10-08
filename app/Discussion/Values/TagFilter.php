<?php

namespace App\Discussion\Values;

/**
 * Tag values chosen per category: OR within a category, AND across them —
 * the rule HasTags::whereTagFilter() applies. Normalises form input, where ids
 * arrive as strings and empty choices as ''.
 */
final readonly class TagFilter
{
    /** @param  array<int, list<int>>  $byCategory  category id => value ids */
    public function __construct(public array $byCategory = []) {}

    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $map = [];

        foreach ($data as $categoryId => $valueIds) {
            $ids = array_values(array_unique(array_map('intval', array_filter((array) $valueIds, 'is_numeric'))));

            if (is_numeric($categoryId) && $ids !== []) {
                $map[(int) $categoryId] = $ids;
            }
        }

        ksort($map);

        return new self($map);
    }

    /** @return array<int, list<int>> */
    public function toArray(): array
    {
        return $this->byCategory;
    }

    /** @return list<int> */
    public function valueIds(): array
    {
        return array_values(array_merge([], ...array_values($this->byCategory)));
    }

    public function isEmpty(): bool
    {
        return $this->byCategory === [];
    }

    /**
     * Drops values that no longer exist. A category whose chosen values are
     * all gone keeps them: emptied, it would stop filtering and silently widen
     * the filter to everything, so it stays unsatisfiable instead.
     *
     * @param  list<int>  $ids
     */
    public function without(array $ids): self
    {
        return new self(array_map(
            fn (array $values) => array_values(array_diff($values, $ids)) ?: $values,
            $this->byCategory,
        ));
    }

    /**
     * True when some category names none of the given existing values, so no
     * model can match the filter.
     *
     * @param  list<int>  $existingIds
     */
    public function isUnsatisfiable(array $existingIds): bool
    {
        foreach ($this->byCategory as $values) {
            if (array_intersect($values, $existingIds) === []) {
                return true;
            }
        }

        return false;
    }
}
