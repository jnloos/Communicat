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

    protected $fillable = [
        'title', 'description', 'user_id',
        'model', 'pipeline', 'turn_budget', 'seed', 'run_config',
        'long_term_memory', 'summarized_until_message_id',
        'summarize_threshold', 'summarize_oldest',
    ];

    protected $casts = ['run_config' => 'array'];

    public const MAX_CONTRIBUTING_EXPERTS = 4;

    /** Per-instance cache for contributingExperts() (hit several times per turn). */
    private ?Collection $cachedContributingExperts = null;

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function experts(): MorphToMany
    {
        return $this->morphedByMany(Expert::class, 'contributor', 'project_contributors')
            ->withPivot('seat')
            ->orderBy('project_contributors.seat')
            ->orderBy('experts.id');
    }

    public function users(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'contributor', 'project_contributors')
            ->withPivot('project_group_id');
    }

    /**
     * Into which of this user's sidebar groups they filed this project, or null
     * for the ungrouped list. Read from that user's own pivot row, so the same
     * project answers differently for everyone who reads it.
     */
    public function groupIdFor(User $user): ?int
    {
        $groupId = $this->users()->whereKey($user->id)->first()?->pivot->project_group_id;

        return $groupId === null ? null : (int) $groupId;
    }

    /** Writes the filing on this user's own pivot row; null empties the drawer. */
    public function fileInGroupFor(User $user, ?int $groupId): void
    {
        $this->users()->updateExistingPivot($user->id, ['project_group_id' => $groupId]);
    }

    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function isPersistent(): bool
    {
        return $this->experts()->count() > 0 || $this->users()->count() > 1;
    }

    public function addContributingExpert(Expert $expert): void
    {
        if ($this->experts()->whereKey($expert->id)->exists()) {
            return;
        }

        $this->experts()->attach($expert->id, ['seat' => $this->nextSeat()]);
        $this->cachedContributingExperts = null;
    }

    public function removeContributingExpert(Expert $expert): void
    {
        $this->experts()->detach($expert->id);
        $this->cachedContributingExperts = null;
    }

    private function nextSeat(): int
    {
        return (int) $this->experts()->max('project_contributors.seat') + 1;
    }

    public function contributingExperts(): Collection
    {
        return $this->cachedContributingExperts ??= $this->experts()->get();
    }

    /**
     * Contributing experts keyed by id. The single source for resolving an
     * id the moderator returned back to its Expert — always project-scoped,
     * never a global name lookup.
     *
     * @return Collection<int, Expert>
     */
    public function contributorMap(): Collection
    {
        return $this->contributingExperts()->keyBy('id');
    }

    public function canAddExpert(): bool
    {
        return $this->experts()->count() < self::MAX_CONTRIBUTING_EXPERTS;
    }

    /**
     * Resolve a prompt token ("E7"/"U3") back to its contributor — the single,
     * project-scoped entry point for turning an id the LLM returned into a model.
     * Experts resolve against the contributor map; users against the project's
     * participants (including the owner). Unknown/malformed tokens yield null.
     */
    public function contributorByPromptId(?string $token): Expert|User|null
    {
        if (! is_string($token) || ! preg_match('/^([EU])(\d+)$/', trim($token), $m)) {
            return null;
        }

        $id = (int) $m[2];

        if ($m[1] === 'E') {
            return $this->contributorMap()->get($id);
        }

        return $this->users()->whereKey($id)->first()
            ?? ($this->owner?->id === $id ? $this->owner : null);
    }

    public function addContributingUser(User $user): void
    {
        $this->users()->syncWithoutDetaching($user->id);
    }

    public function removeContributingUser(User $user): void
    {
        $this->users()->detach($user->id);
    }

    public function contributingUsers(): Collection
    {
        return $this->users()->get();
    }

    public function hasContributor(User $user): bool
    {
        return $this->isOwner($user) || $this->users()->whereKey($user->id)->exists();
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->pipeline ??= config('discussion.default_pipeline');
            $project->model ??= config('ai.default_model');
            $project->seed ??= random_int(1, 2_000_000_000);
        });
    }

    public function addMessage(string $content, Expert|User|null $sender = null): Message
    {
        $message = new Message;
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
    public function participantMessages(): HasMany
    {
        return $this->messages()->where(function ($q) {
            $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
        });
    }

    public function latestParticipantMessage(): ?Message
    {
        return $this->participantMessages()->latest('id')->first();
    }

    /**
     * How many unsummarized messages must pile up before Summarize folds (x).
     * The single place the config fallback lives — the stage and the run_config
     * snapshot both read the effective value from here.
     */
    public function summarizeThreshold(): int
    {
        return (int) ($this->summarize_threshold ?? config('discussion.summarize_threshold'));
    }

    /** How many of the oldest unsummarized messages a fold compresses (y, < x). */
    public function summarizeOldest(): int
    {
        return (int) ($this->summarize_oldest ?? config('discussion.summarize_oldest'));
    }
}
