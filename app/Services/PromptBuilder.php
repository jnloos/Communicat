<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;

class PromptBuilder
{
    public function expertSummaries(Project $project, Expert $expert): string {
        return view('prompts.multiple.expert-summaries', [
            'project' => $project->asPromptArray(),
            'expert'  => $expert->asPromptArray($project),
        ])->render();
    }

    public function nextMessage(Project $project, Expert $expert): string {
        return view('prompts.multiple.next-message', [
            'project' => $project->asPromptArray(),
            'expert'  => $expert->asPromptArray($project),
        ])->render();
    }
}
