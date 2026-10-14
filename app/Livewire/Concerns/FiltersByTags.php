<?php

namespace App\Livewire\Concerns;

use App\Discussion\Values\TagFilter;
use App\Models\TagCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Narrowing a list by tag values, in one of two modes (cut or merge).
 *
 * The host component must declare both properties itself, because only it
 * knows where they persist (#[Session] in the sidebar, #[Url] on the experts
 * page):
 *
 *     public array $tagFilter = [];
 *     public string $tagFilterMode = self::FILTER_CUT;
 *
 * $tagFilter holds the picked value ids (bound to the menu's checkboxes),
 * $tagFilterMode is cut (a row matches every picked category, any picked value
 * within one) or merge (any one picked value is enough). The queried model
 * must use HasTags.
 */
trait FiltersByTags
{
    public const FILTER_CUT = 'cut';

    public const FILTER_MERGE = 'merge';

    /** Empties the chosen values; the mode is a preference and stays. */
    public function clearTagFilter(): void
    {
        $this->tagFilter = [];
    }

    /**
     * The property as plain id strings; it is client-writable, so anything
     * else in it is dropped.
     *
     * @return list<string>
     */
    private function chosenIds(): array
    {
        return array_values(array_map('strval', array_filter($this->tagFilter, 'is_numeric')));
    }

    /**
     * The chosen values that still exist, by their category. A value deleted
     * since it was chosen must not leave the list filtered by nothing.
     *
     * @param  Collection<int, TagCategory>  $categories  the categories of the list's scope with their values
     */
    private function activeTagFilter(Collection $categories): TagFilter
    {
        $chosen = $this->chosenIds();

        return TagFilter::fromArray($categories->mapWithKeys(fn (TagCategory $category) => [
            $category->id => $category->values->pluck('id')
                ->filter(fn ($id) => in_array((string) $id, $chosen, true))
                ->all(),
        ])->all());
    }

    /** Narrows a query by the chosen values in the chosen mode. */
    private function applyTagFilter(Builder|Relation $query, TagFilter $filter): Builder|Relation
    {
        return $this->tagFilterMode === self::FILTER_MERGE
            ? $query->whereTaggedAny($filter->valueIds())
            : $query->whereTagFilter($filter);
    }

    /**
     * Both filter properties are client-writable and persisted (session or
     * URL), so each render first puts them back into shape: anything but merge
     * reads as the default mode, and values that no longer exist are
     * forgotten, so the persisted state does not carry them on.
     *
     * @param  Collection<int, TagCategory>  $categories  the categories of the list's scope with their values
     * @return TagFilter the chosen values that still apply
     */
    private function normaliseFilterState(Collection $categories): TagFilter
    {
        if ($this->tagFilterMode !== self::FILTER_MERGE) {
            $this->tagFilterMode = self::FILTER_CUT;
        }

        $filter = $this->activeTagFilter($categories);
        $valueIds = $filter->valueIds();

        if (count($valueIds) !== count($this->tagFilter)) {
            $this->tagFilter = array_map('strval', $valueIds);
        }

        return $filter;
    }
}
