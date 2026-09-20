<?php

namespace App\Discussion;

use App\Discussion\Values\Contribution;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;

/**
 * The one carrier every pipeline sends through its stages. Stages write their
 * result and read what earlier stages wrote; reading a field nobody has written
 * fails loudly and says which stage is missing.
 */
class TurnPayload
{
    public bool $stop = false;

    public ?string $reason = null;

    /** @var array<int, Thought> expert id → thought */
    private array $thoughts = [];

    private ?Selection $selection = null;

    private ?Contribution $contribution = null;

    private ?Message $message = null;

    public function __construct(
        public readonly Project $project,
        public readonly int $turnIndex,
        public readonly ?int $jobLogId = null,
    ) {}

    public function addThought(Thought $thought): void
    {
        $this->thoughts[$thought->expertId] = $thought;
    }

    /** @return array<int, Thought> */
    public function thoughts(): array
    {
        return $this->thoughts;
    }

    public function thoughtOf(Expert $expert): ?Thought
    {
        return $this->thoughts[$expert->id] ?? null;
    }

    public function select(Selection $selection): void
    {
        $this->selection = $selection;
    }

    public function hasSelection(): bool
    {
        return $this->selection !== null;
    }

    public function selection(): Selection
    {
        return $this->selection
            ?? throw new MissingPayloadSlot('No speaker selected yet. Put SelectSpeaker before this stage.');
    }

    public function contribute(Contribution $contribution): void
    {
        $this->contribution = $contribution;
    }

    public function hasContribution(): bool
    {
        return $this->contribution !== null;
    }

    public function contribution(): Contribution
    {
        return $this->contribution
            ?? throw new MissingPayloadSlot('No contribution yet. Put Speak before this stage.');
    }

    public function persisted(Message $message): void
    {
        $this->message = $message;
    }

    public function hasMessage(): bool
    {
        return $this->message !== null;
    }

    public function message(): Message
    {
        return $this->message
            ?? throw new MissingPayloadSlot('No message persisted yet. Put PersistMessage before this stage.');
    }

    public function halt(string $reason): void
    {
        $this->stop = true;
        $this->reason = $reason;
    }
}
