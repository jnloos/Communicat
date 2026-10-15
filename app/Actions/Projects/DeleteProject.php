<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Cookie;

/**
 * Deletes a project. Authorization stays with the caller.
 */
class DeleteProject
{
    public function handle(Project $project): void
    {
        $project->delete();

        // The dashboard reopens the project this cookie names; one that names the
        // deleted project would point nowhere. Another project's cookie stays, so
        // deleting from the list while reading something else changes nothing.
        if ((string) Cookie::get('curr_project') === (string) $project->id) {
            Cookie::expire('curr_project');
        }
    }
}
