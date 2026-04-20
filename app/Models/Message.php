<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public function expert(): BelongsTo {
        return $this->belongsTo(Expert::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo {
        return $this->belongsTo(Project::class);
    }

    public function isAssistant(): bool {
        return is_null($this->expert_id) && is_null($this->user_id);
    }

    public function isUser(): bool {
        return !is_null($this->user_id);
    }

    public function isExpert(): bool {
        return !is_null($this->expert_id);
    }

    public function isCurrUser(): bool {
        return $this->isUser() && $this->user_id === auth()->id();
    }

    public function sender(): Expert|User|null {
        if ($this->isExpert()) return $this->expert;
        if ($this->isUser()) return $this->user;
        return null;
    }

    public function toPromptArray(): array {
        if ($this->expert_id !== null) {
            $this->loadMissing('expert');
            $name = $this->expert->name;
        } elseif ($this->user_id !== null) {
            $this->loadMissing('user');
            $name = $this->user->name;
        } else {
            $name = 'System';
        }

        return [
            'expert_id' => $this->expert_id,
            'name'      => $name,
            'content'   => $this->content,
        ];
    }
}
