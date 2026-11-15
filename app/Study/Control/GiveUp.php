<?php

namespace App\Study\Control;

use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Services\Tags\TagNames;

/**
 * A run given up for good carries the project tag `superseded: true`. The tag is the
 * lasting record: it outlives failed_jobs:prune and reaches runs.csv through the tag
 * columns. The planner (sub-project B) decides whether a replacement run is created.
 */
class GiveUp
{
    private const VALUE = 'true';

    public function mark(Project $project): void
    {
        $category = $this->category() ?? TagCategory::create(['scope' => TagScope::Project, 'name' => config('study.tags.superseded')]);
        $value = $category->values()->get()->first(fn (TagValue $value) => TagNames::same($value->name, self::VALUE))
            ?? $category->values()->create(['name' => self::VALUE]);

        $project->setTag($category, $value);
    }

    public function isMarked(Project $project): bool
    {
        return $this->markedAmong([$project->id]) !== [];
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    public function markedAmong(array $projectIds): array
    {
        $category = $this->category();

        if ($category === null) {
            return [];
        }

        $valueIds = $category->values()->get()->filter(fn (TagValue $value) => TagNames::same($value->name, self::VALUE))->modelKeys();

        return Taggable::where('taggable_type', (new Project)->getMorphClass())
            ->whereIn('taggable_id', $projectIds)
            ->whereIn('tag_value_id', $valueIds)
            ->pluck('taggable_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function category(): ?TagCategory
    {
        return TagCategory::ofScope(TagScope::Project)->get()
            ->first(fn (TagCategory $category) => TagNames::same($category->name, config('study.tags.superseded')));
    }
}
