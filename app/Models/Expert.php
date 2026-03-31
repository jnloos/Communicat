<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Expert extends Model
{
    public function summaries(): HasMany {
        return $this->hasMany(Summary::class);
    }

    public function projects(): MorphToMany {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors');
    }

    public function thoughtsAbout(int|Project $project): Summary {
        if ($project instanceof Project) {
            $project = $project->id;
        }

        return Summary::firstOrCreate(
            ['project_id' => $project, 'expert_id' => $this->id],
            ['content' => '']
        );
    }

    public function isContributing(Project $project): bool {
        return $this->projects()->whereKey($project->id)->exists();
    }

    public function asPromptArray(Project $project): array {
        $summary = $this->thoughtsAbout($project);

        return [
            'name'        => $this->name,
            'expert_id'   => $this->id,
            'job'         => $this->job,
            'description' => $this->prompt,
            'thoughts'    => $summary,
        ];
    }
}
