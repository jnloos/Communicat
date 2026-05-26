<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'settings', 'user_id'];

    protected $casts = ['settings' => 'array'];

    public const MAX_CONTRIBUTING_EXPERTS = 4;

    /** Per-instance cache for contributingExperts() (hit several times per turn). */
    private ?Collection $cachedContributingExperts = null;

    public function messages(): HasMany {
        return $this->hasMany(Message::class);
    }

    public function summaries(): HasMany {
        return $this->hasMany(Summary::class);
    }

    public function owner(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function experts(): MorphToMany {
        return $this->morphedByMany(Expert::class, 'contributor', 'project_contributors');
    }

    public function users(): MorphToMany {
        return $this->morphedByMany(User::class, 'contributor', 'project_contributors');
    }

    public function isOwner(User $user): bool {
        return $this->user_id === $user->id;
    }

    public function isPersistent(): bool {
        return $this->experts()->count() > 0 || $this->users()->count() > 1;
    }

    public function addContributingExpert(Expert $expert): void {
        $this->experts()->syncWithoutDetaching($expert->id);
    }

    public function removeContributingExpert(Expert $expert): void {
        $this->experts()->detach($expert->id);
    }

    public function contributingExperts(): Collection {
        return $this->cachedContributingExperts ??= $this->experts()->get();
    }

    /**
     * Contributing experts keyed by id. The single source for resolving an
     * id the moderator returned back to its Expert — always project-scoped,
     * never a global name lookup.
     *
     * @return Collection<int, Expert>
     */
    public function contributorMap(): Collection {
        return $this->contributingExperts()->keyBy('id');
    }

    public function canAddExpert(): bool {
        return $this->experts()->count() < self::MAX_CONTRIBUTING_EXPERTS;
    }

    /**
     * Resolve a prompt token ("E7"/"U3") back to its contributor — the single,
     * project-scoped entry point for turning an id the LLM returned into a model.
     * Experts resolve against the contributor map; users against the project's
     * participants (including the owner). Unknown/malformed tokens yield null.
     */
    public function contributorByPromptId(?string $token): Expert|User|null {
        if (!is_string($token) || !preg_match('/^([EU])(\d+)$/', trim($token), $m)) {
            return null;
        }

        $id = (int) $m[2];

        if ($m[1] === 'E') {
            return $this->contributorMap()->get($id);
        }

        return $this->users()->whereKey($id)->first()
            ?? ($this->owner?->id === $id ? $this->owner : null);
    }

    public function addContributingUser(User $user): void {
        $this->users()->syncWithoutDetaching($user->id);
    }

    public function removeContributingUser(User $user): void {
        $this->users()->detach($user->id);
    }

    public function contributingUsers(): Collection {
        return $this->users()->get();
    }

    public function hasContributor(User $user): bool {
        return $this->isOwner($user) || $this->users()->whereKey($user->id)->exists();
    }

    /**
     * Resolve the concrete user a turn hands the floor back to: the author of
     * the pending (unanswered) user message when there is one, otherwise the
     * project owner. Replaces the generic 'Nutzer' sentinel so multi-user
     * projects know which human is addressed.
     */
    public function handoffUser(?Message $pendingUser = null): ?User {
        if ($pendingUser !== null && $pendingUser->user_id !== null) {
            return $pendingUser->user;
        }

        return $this->owner;
    }

    protected static function booted(): void {
        static::creating(function (Project $project): void {
            if (auth()->check()) {
                $project->user_id = auth()->id();
            }
        });

        static::created(function (Project $project): void {
            if (auth()->check()) {
                $project->users()->syncWithoutDetaching(auth()->id());
            }

            $welcomeMsg = view('components.projects.welcome-message', [
                'project' => $project
            ])->render();
            $project->addMessage($welcomeMsg);
        });
    }

    public function addMessage(string $content, Expert|User|null $sender = null): Message {
        $message = new Message();
        $message->project_id = $this->id;
        $message->content = $content;

        if ($sender instanceof Expert) {
            $message->expert_id = $sender->id;
        } elseif ($sender instanceof User) {
            $message->user_id = $sender->id;
        }

        $message->save();
        return $message;
    }

    /** Messages from participants (expert or user), excluding system/assistant. */
    private function participantMessages(): HasMany {
        return $this->messages()->where(function ($q) {
            $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
        });
    }

    public function latestParticipantMessage(): ?Message {
        return $this->participantMessages()->latest('id')->first();
    }

    public function asPromptArray(int $numMsg = -1): array {
        $lastSummarizedId = $this->settings['last_summarized_id'] ?? 0;

        // Pull newest-first so an optional take($numMsg) yields the most
        // recent window, then reverse to chronological order before handing
        // the list to prompts — LLMs read top-down and treat the last line
        // as the most recent turn, so the user's latest message must be
        // last in the rendered list.
        $query = $this->participantMessages()
            ->where('id', '>', $lastSummarizedId)
            ->orderBy('id', 'desc');

        if ($numMsg > -1) {
            $query->take($numMsg);
        }

        $messages = $query->get()
            ->reverse()
            ->map(fn(Message $msg) => $msg->toPromptArray())
            ->values()
            ->all();

        return [
            'title'        => $this->title,
            'description'  => $this->description,
            'chat_summary' => $this->settings['chat_summary'] ?? '',
            'messages'     => $messages,
        ];
    }
}
