<?php

use App\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('projects.{projectId}', function ($user, int $projectId) {
    $project = Project::find($projectId);

    // The shared onboarding demo is readable by every authenticated user, so its
    // realtime channel is open to all (it never emits user-specific data).
    return $project && ($project->isDemo() || $project->hasContributor($user));
});

Broadcast::channel('debug', function ($user) {
    return auth()->check();
});
