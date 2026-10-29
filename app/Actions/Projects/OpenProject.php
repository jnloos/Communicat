<?php

namespace App\Actions\Projects;

use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens a discussion: the project row, its tags, its owner as a contributor,
 * the experts in seat order and the welcome notice. The one path for the form
 * in the UI and for project:create, so a project made either way looks alike.
 */
class OpenProject
{
    /**
     * @param  array<string, mixed>  $attributes  project columns; unset ones fall back in Project::booted()
     * @param  list<Expert>  $experts  seated in the given order
     * @param  array<int|string, int|string|null>  $tags  category id => value id, as syncTags() takes it
     * @param  array<int, AttributeVisibility|string>|null  $visibilities  attribute id => level; null = Project::defaultAttributeVisibilities()
     */
    public function handle(User $owner, array $attributes, array $experts = [], array $tags = [], ?array $visibilities = null): Project
    {
        // One transaction, so a forged tag value does not leave a half-created project behind.
        $project = DB::transaction(function () use ($owner, $attributes, $tags, $visibilities) {
            $project = Project::create(['user_id' => $owner->id] + $attributes);
            $project->syncTags($tags);
            $project->setAttributeVisibilities($visibilities ?? Project::defaultAttributeVisibilities());

            return $project;
        });

        // Owning a project is not the same as being in it: the sidebar lists
        // projects through the contributor pivot, so without this the project
        // exists, is reachable by URL, and appears nowhere.
        $project->addContributingUser($owner);

        foreach ($experts as $expert) {
            $project->addContributingExpert($expert);
        }

        // The welcome notice carries no sender, so participantMessages() leaves
        // it out of every prompt and every count.
        $project->addMessage(view('components.projects.welcome-message', ['project' => $project])->render());

        return $project;
    }
}
