<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expert extends Model
{
    public function summaries(): HasMany {
        return $this->hasMany(Summary::class);
    }

    public function projects(): BelongsToMany {
        return $this->belongsToMany(Project::class, 'expert_project');
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
