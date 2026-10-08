<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A global tag category with fixed values, for personas or for projects. */
class TagCategory extends Model
{
    use HasPosition;

    protected $fillable = ['scope', 'name', 'position'];

    protected $casts = ['scope' => TagScope::class];

    public function values(): HasMany
    {
        return $this->hasMany(TagValue::class)->orderBy('position')->orderBy('id');
    }

    /** The name as the interface shows it ("Title-holder"); the stored name stays as typed. */
    public function label(): string
    {
        return self::labelFor($this->name);
    }

    /** The display rule of label(), for a category name that has no model (e.g. a template's). */
    public static function labelFor(string $name): string
    {
        return Str::ucfirst($name);
    }

    /** The categories of one scope, in their manual order. */
    public function scopeOfScope(Builder $query, TagScope $scope): void
    {
        $query->where('scope', $scope->value)->orderBy('position')->orderBy('id');
    }

    /** How many models carry a value of this category (at most one each). */
    public function assignmentCount(): int
    {
        return Taggable::where('tag_category_id', $this->id)->count();
    }

    protected function siblings(): Builder
    {
        return static::query()->where('scope', $this->scope->value);
    }
}
