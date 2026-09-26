<?php

namespace App\Console\Commands\Concerns;

use App\Models\Project;

/** Turning the `project` argument into a project, with one wording for the failure. */
trait ResolvesProject
{
    /** Null when there is no such project; the message is already on the console. */
    protected function project(): ?Project
    {
        $id = (int) $this->argument('project');
        $project = Project::find($id);

        if ($project === null) {
            $this->error("No project with id {$id}. List them with: php artisan project:list");
        }

        return $project;
    }
}
