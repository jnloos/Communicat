<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Expert extends Model
{
    use HasFactory;

    /**
     * Stable, type-prefixed token used to reference this contributor inside
     * prompts and structured LLM outputs ("E7"). Disambiguates experts from
     * users (and from same-named experts) without ever matching on names.
     */
    protected function promptId(): Attribute
    {
        return Attribute::get(fn () => 'E' . $this->id);
    }

    protected $fillable = [
        'name',
        'avatar_url',
        'job',
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

    public function asPromptArray(Project $project): array
    {
        return [
            'name'        => $this->name,
            'expert_id'   => $this->id,
            'prompt_id'   => $this->promptId,
            'job'         => $this->job,
            'description' => $this->description,
            'thoughts'    => $this->thoughtsAbout($project),
        ];
    }
}
