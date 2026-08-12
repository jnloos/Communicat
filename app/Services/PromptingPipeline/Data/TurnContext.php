<?php

namespace App\Services\PromptingPipeline\Data;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Mutable carrier threaded through the turn pipeline. Each stage reads what it
 * needs and writes its result back onto the same instance.
 */
class TurnContext
{
    /** Latest participant message, resolved once in ResolveModerationContext. */
    public ?Message $latestMessage = null;

    public string $moderationNote = '';

    /** Advisory signals for the moderator (agenda phase, pending/unanswered user). */
    public ?array $moderationContext = null;

    /** @var Expert[] candidate pool for this turn */
    public array $candidates = [];

    /** True when the turn was short-circuited by a user @-mention. */
    public bool $mentionShortcut = false;

    /**
     * Experts added to the THINK batch only to refresh a stale memory. They
     * produce THINK output but must never win the turn — the moderator picked
     * the candidates, not them.
     *
     * @var Collection<int, Expert>
     */
    public Collection $catchUpExperts;

    public ?Directive $directive = null;

    /** @var array<int, array{memory: string, beitragsabsicht: string, topic_done: bool}> expert id → THINK output */
    public array $thinkOutputs = [];

    public ?Expert $winner = null;

    /**
     * The first pair part the winner is expected to close this turn, if any.
     * Resolved once the winner is known and used twice: SPEAK renders the
     * question verbatim, PersistMessage records the closure.
     */
    public ?Message $openPair = null;

    /** @var array{content: string}|null SPEAK output is the visible turn text only. */
    public ?array $speakResult = null;

    public ?Message $message = null;

    public bool $stop = false;

    public ?string $reason = null;

    public function __construct(
        public Project $project,
        public ?int $jobLogId = null,
    ) {
        $this->catchUpExperts = collect();
    }
}
