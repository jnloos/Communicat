<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public function expert(): BelongsTo
    {
        return $this->belongsTo(Expert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The contributing expert this message addresses, if any. Set from the SPEAK
     * output; null means the turn spoke to the group. Only an expert can be
     * addressed — users cannot contribute to a run.
     */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(Expert::class, 'addressee_expert_id');
    }

    public function isAssistant(): bool
    {
        return is_null($this->expert_id) && is_null($this->user_id);
    }

    public function isUser(): bool
    {
        return ! is_null($this->user_id);
    }

    public function isExpert(): bool
    {
        return ! is_null($this->expert_id);
    }

    /**
     * The addressee the chat shows: none when the turn spoke to the group, and
     * none when a model addressed its own speaker, which says nothing to a reader.
     */
    public function visibleAddressee(): ?Expert
    {
        $addressee = $this->addressee;

        return $addressee?->id === $this->expert_id ? null : $addressee;
    }

    public function sender(): Expert|User|null
    {
        if ($this->isExpert()) {
            return $this->expert;
        }
        if ($this->isUser()) {
            return $this->user;
        }

        return null;
    }
}
