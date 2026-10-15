<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Expert extends Model
{
    use HasFactory, HasPublicUuid, HasTags;

    /**
     * Stable, type-prefixed token used to reference this contributor inside
     * prompts and structured LLM outputs ("E7"). Disambiguates experts from
     * users (and from same-named experts) without ever matching on names.
     */
    protected function promptId(): Attribute
    {
        return Attribute::get(fn () => self::tokenFor($this->id));
    }

    /** The token for an expert id, the one format every prompt, selection signal and export uses. */
    public static function tokenFor(int $id): string
    {
        return 'E'.$id;
    }

    protected $fillable = [
        'name',
        'avatar_url',
        'role',
        'description',
    ];

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    public function projects(): MorphToMany
    {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors');
    }

    /** Experts whose name, role or description contains the term; a blank term matches everyone. */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('role', 'like', $like)
            ->orWhere('description', 'like', $like));
    }
}
