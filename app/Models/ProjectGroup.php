<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A drawer in one person's sidebar. A group belongs to a user, never to a
 * project: the same shared project may sit in a different group for every
 * person who reads it.
 */
class ProjectGroup extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'position'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The projects this group's owner filed here.
     *
     * The join runs over project_contributors.project_group_id. That column is
     * only ever written on the owning user's own row, so the group id alone
     * already narrows the join to that one person — no contributor filter is
     * needed to keep another user's filing out.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_contributors', 'project_group_id', 'project_id')
            ->orderByDesc('projects.updated_at');
    }
}
