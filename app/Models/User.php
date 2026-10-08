<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Stable, type-prefixed token used to reference this contributor inside
     * prompts and structured LLM outputs ("U3"). The "U" prefix distinguishes
     * users from experts ("E7") so a partner reference is never ambiguous.
     */
    protected function promptId(): Attribute
    {
        return Attribute::get(fn () => 'U'.$this->id);
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // projects.user_id cascades in the database, which would skip every
        // project's own deleting hooks (HasTags clears its taggables there).
        // Deleting through Eloquent first leaves no orphaned tag assignment.
        static::deleting(function (User $user): void {
            $user->ownedProjects()->each(fn (Project $project) => $project->delete());
        });
    }

    public function projects(): MorphToMany
    {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors')
            ->withPivot('project_group_id');
    }

    /**
     * This user's sidebar drawers. Ordered by position first so a future
     * reordering UI has something to write to; the name only breaks ties.
     */
    public function projectGroups(): HasMany
    {
        return $this->hasMany(ProjectGroup::class)
            ->orderBy('position')
            ->orderBy('name');
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'user_id');
    }

    /** This user's saved comparisons on the statistics page. */
    public function comparisons(): HasMany
    {
        return $this->hasMany(Comparison::class)->orderBy('name')->orderBy('id');
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
