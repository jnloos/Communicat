<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Stable, type-prefixed token used to reference this contributor inside
     * prompts and structured LLM outputs ("U3"). The "U" prefix distinguishes
     * users from experts ("E7") so a partner reference is never ambiguous.
     */
    protected function promptId(): Attribute
    {
        return Attribute::get(fn () => 'U' . $this->id);
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
            'password'          => 'hashed',
            'is_admin'          => 'boolean',
        ];
    }

    public function projects(): MorphToMany {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors');
    }

    public function ownedProjects(): HasMany {
        return $this->hasMany(Project::class, 'user_id');
    }

    public function initials(): string {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn(string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
