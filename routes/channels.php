<?php

use App\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('projects.{projectId}', function ($user, int $projectId) {
    $project = Project::find($projectId);

    return $project !== null && $user->can('view', $project);
});
