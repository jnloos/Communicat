<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasTags;
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

    public function thoughtsAbout(int|Project $project): Summary
    {
        if ($project instanceof Project) {

            $project = $project->id;
        }

        return Summary::firstOrCreate(
            ['project_id' => $project, 'expert_id' => $this->id],
            ['content' => '']
        );
    }

    public function isContributing(Project $project): bool
    {
        return $this->projects()->whereKey($project->id)->exists();
    }
}
