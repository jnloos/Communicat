<?php

namespace App\Models\Concerns;

use App\Discussion\Values\TagFilter;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;

/**
 * Global tags on a model: at most one value per category, and only categories
 * of the model's own scope. Tags describe a run after the fact — nothing that
 * builds a prompt or a run_config may read them.
 */
trait HasTags
{
    public static function bootHasTags(): void
    {
        // taggables has no foreign key to the tagged row (it is polymorphic), so
        // the assignments go with the model here. A mass delete bypasses this.
        static::deleting(function (Model $model): void {
            Taggable::where('taggable_type', $model->getMorphClass())
                ->where('taggable_id', $model->getKey())
                ->delete();
        });
    }

    public function tagValues(): MorphToMany
    {
        return $this->morphToMany(TagValue::class, 'taggable', 'taggables')
            ->withPivot('tag_category_id')
            ->withTimestamps();
    }

    public function tagScope(): TagScope
    {
        return TagScope::forModel($this);
    }

    /** Sets (or with null clears) this model's value for one category; replaces, never adds. */
    public function setTag(TagCategory $category, ?TagValue $value): void
    {
        if ($category->scope !== $this->tagScope()) {
            throw new InvalidArgumentException("Category [{$category->name}] belongs to [{$category->scope->value}], not [{$this->tagScope()->value}].");
        }

        if ($value !== null && (int) $value->tag_category_id !== (int) $category->id) {
            throw new InvalidArgumentException("Value [{$value->name}] does not belong to category [{$category->name}].");
        }

        $row = [
            'taggable_type' => $this->getMorphClass(),
            'taggable_id' => $this->getKey(),
            'tag_category_id' => $category->id,
        ];

        if ($value === null) {
            Taggable::where($row)->delete();
        } else {
            Taggable::upsert(
                [$row + ['tag_value_id' => $value->id]],
                ['taggable_type', 'taggable_id', 'tag_category_id'],
                ['tag_value_id'],
            );
        }

        $this->unsetRelation('tagValues');
    }

    public function tagFor(TagCategory $category): ?TagValue
    {
        return $this->tagValues()->wherePivot('tag_category_id', $category->id)->first();
    }

    /**
     * The model's tags as a form holds them.
     *
     * @return array<int, int> category id => value id, only for categories that are set
     */
    public function tagSelection(): array
    {
        return $this->tagValues()->get()
            ->mapWithKeys(fn (TagValue $value) => [(int) $value->pivot->tag_category_id => $value->id])
            ->all();
    }

    /**
     * Writes a form's selection. Every category of this model's scope that
     * appears as a key is set; '' or null clears it. A value that is not one of
     * its category's values is a forged request and throws.
     *
     * @param  array<int|string, int|string|null>  $selection  category id => value id
     */
    public function syncTags(array $selection): void
    {
        $categories = TagCategory::ofScope($this->tagScope())
            ->whereKey(array_keys($selection))
            ->with('values')
            ->get();

        foreach ($categories as $category) {
            $raw = $selection[$category->id] ?? null;
            $value = blank($raw) ? null : $category->values->firstWhere('id', (int) $raw);

            if (! blank($raw) && $value === null) {
                throw new InvalidArgumentException("Value [{$raw}] does not belong to category [{$category->name}].");
            }

            $this->setTag($category, $value);
        }
    }

    /**
     * Only models that carry at least one of the values, whatever their
     * category. An empty list filters nothing; unknown ids match nothing.
     *
     * @param  list<int>  $valueIds
     */
    public function scopeWhereTaggedAny(Builder $query, array $valueIds): void
    {
        if ($valueIds === []) {
            return;
        }

        $query->whereHas('tagValues', fn (Builder $tagged) => $tagged->whereIn('tag_values.id', $valueIds));
    }

    /**
     * Only models that carry the filter's values: AND across its categories, OR
     * within one. An empty filter filters nothing; a category whose values have
     * all been deleted matches nothing instead of dropping out.
     */
    public function scopeWhereTagFilter(Builder $query, TagFilter $filter): void
    {
        foreach ($filter->byCategory as $categoryId => $valueIds) {
            $query->whereHas('tagValues', fn (Builder $tagged) => $tagged
                ->where('tag_values.tag_category_id', $categoryId)
                ->whereIn('tag_values.id', $valueIds));
        }
    }
}
