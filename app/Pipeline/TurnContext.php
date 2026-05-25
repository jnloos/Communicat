<?php

namespace App\Pipeline;

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

    /** Advisory signals for the moderator (open adjacency pair, agenda phase, pending user). */
    public ?array $moderationContext = null;

    /** @var Expert[] candidate pool for this turn */
    public array $candidates = [];

    public ?Directive $directive = null;

    /** @var array<string, array{memory: string, beitragsabsicht: string}> name → THINK output */
    public array $thinkOutputs = [];

    public ?Expert $winner = null;

    /** @var array{content: string}|null SPEAK output is the visible turn text only. */
    public ?array $speakResult = null;

    public ?Message $message = null;

    public bool $stop = false;
    public ?string $reason = null;
    public ?string $nextSpeaker = null;

    public function __construct(
        public Project $project,
        public ?int $jobLogId = null,
    ) {}
}
