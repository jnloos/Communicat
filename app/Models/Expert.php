<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Expert extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'avatar_url',
        'job',
        'description',
        'prompt',
    ];

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    public function projects(): MorphToMany
    {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
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

    public static function findByName(string $name): self
    {
        return static::where('name', trim($name))->firstOrFail();
    }

    public static function findManyByName(array $names): \Illuminate\Support\Collection
    {
        return static::whereIn('name', array_map('trim', $names))->get();
    }

    public function asPromptArray(Project $project): array
    {
        $summary = $this->thoughtsAbout($project);

        return [
            'name' => $this->name,
            'expert_id' => $this->id,
            'job' => $this->job,
            'description' => $this->prompt,
            'thoughts' => $summary,
        ];
    }
}
