<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    /** adjacency_pair_type values. */
    public const PAIR_FRAGE_ANTWORT = 'Frage→Antwort';

    public const PAIR_ANSPRACHE_REAKTION = 'Ansprache→Reaktion';

    public const PAIR_BEITRAG_DISKUSSION = 'Beitrag→Diskussion';

    public const PAIR_SYNTHESE_DISKUSSION = 'Synthese→Diskussion';

    public const PAIR_ABSCHLUSS_NUTZER = 'Abschluss→Nutzer';

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
     * The contributor this message addresses (expert or user), if any. The
     * partner's type encodes the simplified adjacency-pair direction: a User
     * partner is a hand-back to the human, an Expert partner an expert→expert
     * turn. Set from the SPEAK output (expert) or the moderator's hand-off (user).
     */
    public function adjacencyPartner(): MorphTo
    {
        return $this->morphTo();
    }

    public function handsBackToUser(): bool
    {
        return $this->adjacency_partner_type === User::class;
    }

    /**
     * The first pair part this message closes — the incoming counterpart to
     * adjacencyPartner(). A turn may have both: it answers one expert and
     * addresses another.
     */
    public function answers(): BelongsTo
    {
        return $this->belongsTo(self::class, 'answers_message_id');
    }

    /** The turn that closed this message's pair, if it has been closed. */
    public function answeredBy(): HasOne
    {
        return $this->hasOne(self::class, 'answers_message_id');
    }

    /**
     * True when this message opens a pair that still owes a reply: it addressed
     * a specific expert with a question or an address, and nobody has answered.
     */
    public function opensExpertPair(): bool
    {
        return $this->adjacency_partner_type === Expert::class
            && $this->adjacency_partner_id !== null
            && in_array($this->adjacency_pair_type, [self::PAIR_FRAGE_ANTWORT, self::PAIR_ANSPRACHE_REAKTION], true);
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

    public function isCurrUser(): bool
    {
        return $this->isUser() && $this->user_id === auth()->id();
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

    public function toPromptArray(): array
    {
        if ($this->expert_id !== null) {
            $this->loadMissing('expert');
            $name = $this->expert->name;
            $promptId = 'E'.$this->expert_id;
        } elseif ($this->user_id !== null) {
            $this->loadMissing('user');
            $name = $this->user->name;
            $promptId = 'U'.$this->user_id;
        } else {
            $name = 'System';
            $promptId = null;
        }

        return [
            'prompt_id' => $promptId,
            'name' => $name,
            'content' => $this->content,
        ];
    }
}
