<?php

namespace App\Discussion;

use App\Models\Project;
use RuntimeException;

/**
 * A project without a description cannot run: the description is the topic
 * every prompt carries, and the title -- the only other candidate -- is the
 * researcher's label for a cell, which would hand the agents their condition.
 */
class MissingTopic extends RuntimeException
{
    public static function in(Project $project): self
    {
        return new self("Project {$project->id} has no description, so its agents have no topic. Give it one before running it.");
    }
}
