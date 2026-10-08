<?php

namespace App\Services\Tags;

use App\Models\Concerns\HasTags;
use App\Models\TagCategory;
use App\Models\TagValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Tags as they travel between instances: {category name: value name}, because
 * ids differ. Reading is sorted like the settings page; writing never creates a
 * category or a value, it reports what it could not place.
 */
class TagsByName
{
    /**
     * @param  Model&HasTags  $model
     * @return array<string, string>
     */
    public function export(Model $model): array
    {
        return $model->tagValues()->with('category')->get()
            ->sortBy(fn (TagValue $value) => [$value->category->position, $value->category->id])
            ->mapWithKeys(fn (TagValue $value) => [$value->category->name => $value->name])
            ->all();
    }

    /**
     * @param  Model&HasTags  $model
     * @param  bool  $overwrite  false keeps a value the model already has in a category
     * @return list<string> "category: value" for every pair that was skipped
     */
    public function assign(Model $model, mixed $tags, bool $overwrite = true): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $categories = TagCategory::ofScope($model->tagScope())->with('values')->get()->keyBy('name');
        $held = $overwrite ? [] : $model->tagSelection();
        $skipped = [];

        foreach ($tags as $categoryName => $valueName) {
            if (! is_scalar($valueName)) {
                $skipped[] = (string) $categoryName;

                continue;
            }

            $category = $categories->get((string) $categoryName);
            $value = $category?->values->firstWhere('name', (string) $valueName);

            if ($value === null) {
                $skipped[] = "{$categoryName}: {$valueName}";

                continue;
            }

            if (isset($held[$category->id])) {
                continue;
            }

            $model->setTag($category, $value);
        }

        return $skipped;
    }
}
