<?php

namespace App\Models;

use App\Observers\ExpertObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([ExpertObserver::class])]
class Expert extends Model
{
    function summaries(): HasMany {
        return $this->hasMany(Summary::class);
    }

    public function contributor(): HasOne {
        return $this->hasOne(Contributor::class);
    }

    function thoughtsAbout(int|Project $project): ?Summary {
        if ($project instanceof Project) {
            $project = $project->id;
        }

        return Summary::firstOrCreate(
            ['project_id' => $project, 'expert_id' => $this->id],
            ['content' => '']
        );
    }

    public function isContributing(Project $project): bool {
        return $this->contributor?->projects()->whereKey($project->id)->exists() ?? false;
    }

    public function asPromptArray(Project $project): array {
        $summary = $this->thoughtsAbout($project);

        return [
            'name' => $this->name,
            'expert_id' => $this->id,
            'job' => $this->job,
            'description' => $this->prompt,
            'thoughts' => $summary
        ];
    }
}
