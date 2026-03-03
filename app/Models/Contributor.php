<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contributor extends Model
{
    protected $fillable = [
        'user_id',
        'expert_id',
    ];

    public function projects(): BelongsToMany {
        return $this->belongsToMany(Project::class, table: 'contributor_project');
    }

    public function user(): BelongsTo {
        assert($this->isUser());
        return $this->belongsTo(User::class);
    }

    public function isCurrUser(): bool {
        if($this->isUser()) {
            return $this->user_id == auth()->user()->id;
        }
        return false;
    }

    public function expert(): BelongsTo {
        assert($this->isExpert());
        return $this->belongsTo(Expert::class);
    }

    public static function assistant(): Contributor {
        return static::firstOrCreate([
            'user_id'   => null,
            'expert_id' => null,
        ]);
    }

    public function messages(): HasMany {
        return $this->hasMany(Message::class);
    }

    public function isUser(): bool {
        return ! is_null($this->user_id);
    }

    public function isExpert(): bool {
        return ! is_null($this->expert_id);
    }

    public function isAssistant(): bool {
        return is_null($this->user_id) && is_null($this->expert_id);
    }

    public function unmask(): User|Expert|null {
        if ($this->isExpert()) {
            return $this->expert;
        }

        if ($this->isUser()) {
            return $this->user;
        }

        return null;
    }
}
