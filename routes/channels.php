<?php

use App\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('projects.{projectId}', function ($user, int $projectId) {
    $project = Project::find($projectId);
    return $project && $project->hasContributor($user);
});

Broadcast::channel('debug', function ($user) {
    return auth()->check();
});
