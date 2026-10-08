<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TagValue extends Model
{
    use HasPosition;

    protected $fillable = ['tag_category_id', 'name', 'position'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TagCategory::class, 'tag_category_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Taggable::class);
    }

    /**
     * "category: value" — how the interface always names a tag, true/false
     * values included, so a badge never needs its tooltip to be understood.
     * Needs the category loaded.
     */
    public function fullLabel(): string
    {
        return "{$this->category->name}: {$this->name}";
    }

    /**
     * The values a form's selection names, with their category, for showing
     * that selection; ids of other scopes or that no longer exist drop out.
     *
     * @param  array<int|string, int|string|null>  $selection  category id => value id
     * @return Collection<int, TagValue>
     */
    public static function chosenIn(TagScope $scope, array $selection): Collection
    {
        $ids = array_values(array_filter($selection, 'is_numeric'));

        if ($ids === []) {
            return new Collection;
        }

        return static::whereKey($ids)
            ->whereHas('category', fn (Builder $query) => $query->where('scope', $scope->value))
            ->with('category')
            ->get();
    }

    protected function siblings(): Builder
    {
        return static::query()->where('tag_category_id', $this->tag_category_id);
    }
}
