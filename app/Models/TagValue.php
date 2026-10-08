<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
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

    protected function siblings(): Builder
    {
        return static::query()->where('tag_category_id', $this->tag_category_id);
    }
}
