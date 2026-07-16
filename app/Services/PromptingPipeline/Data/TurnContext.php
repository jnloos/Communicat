<?php

namespace App\Services\PromptingPipeline\Data;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;

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

    public ?Directive $directive = null;

    /** @var array<int, array{memory: string, beitragsabsicht: string}> expert id → THINK output */
    public array $thinkOutputs = [];

    public ?Expert $winner = null;

    /** @var array{content: string}|null SPEAK output is the visible turn text only. */
    public ?array $speakResult = null;

    public ?Message $message = null;

    public bool $stop = false;

    public ?string $reason = null;

    public function __construct(
        public Project $project,
        public ?int $jobLogId = null,
    ) {}
}
