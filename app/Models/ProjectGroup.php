<?php

namespace App\Models;

use App\Models\Concerns\HasPosition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A drawer in one person's sidebar. A group belongs to a user, never to a
 * project: the same shared project may sit in a different group for every
 * person who reads it. Its place among its owner's other groups is the manual
 * order of the sidebar (HasPosition): a new group joins at the end.
 */
class ProjectGroup extends Model
{
    use HasFactory, HasPosition;

    protected $fillable = ['user_id', 'name', 'position'];

    /** A group is ordered among its owner's groups only. */
    protected function siblings(): Builder
    {
        return static::query()->where('user_id', $this->user_id);
    }

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
