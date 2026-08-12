<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;

/**
 * The adjacency pairs a project still owes a reply to.
 *
 * An open pair is *derived*, never stored: a message that addressed an expert
 * with a question or an address, and that no later message has answered
 * (`answers_message_id`). Keeping it derived means there is no second copy of
 * the truth to fall out of sync with the messages table.
 *
 * This replaces the previous `openFloorExpert()`, which only ever looked at the
 * single latest message. An obligation to reply therefore survived exactly one
 * turn: a user message, a mention shortcut or a moderator that picked someone
 * else made it vanish, and the addressed expert was never asked again.
 */
class OpenPairRegistry
{
    public function __construct(protected Project $project) {}

    /**
     * The pair that has been waiting longest and still deserves an answer.
     * Oldest first, so nobody is left hanging while newer pairs get served.
     */
    public function oldestOpen(): ?Message
    {
        return $this->openPairs()->first();
    }

    /**
     * All unanswered expert pairs, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, Message>
     */
    public function openPairs()
    {
        $maxAge = max(1, (int) config('discussion.open_pair_max_age_turns', 6));

        // Only look back a bounded window: a question from twenty turns ago is
        // no longer live conversation, and forcing an answer to it would drag
        // the discussion backwards.
        $cutoffId = $this->cutoffMessageId($maxAge);

        return $this->project->messages()
            ->whereNotNull('expert_id')
            ->where('id', '>=', $cutoffId)
            ->where('adjacency_partner_type', Expert::class)
            ->whereNotNull('adjacency_partner_id')
            ->whereIn('adjacency_pair_type', [Message::PAIR_FRAGE_ANTWORT, Message::PAIR_ANSPRACHE_REAKTION])
            ->whereDoesntHave('answeredBy')
            ->orderBy('id')
            ->get()
            // The addressee must still be contributing — an expert removed from
            // the project cannot close anything.
            ->filter(fn (Message $m) => $this->project->contributorMap()->has($m->adjacency_partner_id))
            ->values();
    }

    /**
     * The pair this expert is expected to close, if any.
     */
    public function pairFor(Expert $expert): ?Message
    {
        return $this->openPairs()->firstWhere('adjacency_partner_id', $expert->id);
    }

    /**
     * Prompt-facing description of the oldest open pair, or null.
     *
     * @return array{name: string, prompt_id: string, message_id: int, question: string}|null
     */
    public function oldestOpenSignal(): ?array
    {
        $pair = $this->oldestOpen();

        if ($pair === null) {
            return null;
        }

        $addressee = $this->project->contributorMap()->get($pair->adjacency_partner_id);

        if (! $addressee instanceof Expert) {
            return null;
        }

        return [
            'name' => $addressee->name,
            'prompt_id' => $addressee->promptId,
            'message_id' => $pair->id,
            'question' => $this->questionOf($pair) ?? $this->pointOf($pair),
        ];
    }

    /**
     * The question the addressee actually has to answer, or null when the pair
     * was an address rather than a question (Ansprache→Reaktion has no "?").
     *
     * Returning the whole contribution as a stand-in was actively harmful: the
     * SPEAK prompt presented it as "X asked you: …", and the agent answered by
     * restating it almost verbatim — three near-identical turns in a row.
     */
    public function questionOf(Message $pair): ?string
    {
        $content = trim((string) $pair->content);

        $questions = array_values(array_filter(
            array_map('trim', preg_split('/(?<=[.!?])\s+/u', $content) ?: []),
            fn (string $s) => str_ends_with($s, '?'),
        ));

        if (empty($questions)) {
            return null;
        }

        return $this->clip(end($questions), 240);
    }

    /**
     * What the addressee was addressed *about*, when no question was asked.
     * Deliberately short — it is orientation, not material to repeat.
     */
    public function pointOf(Message $pair): string
    {
        return $this->clip(trim((string) $pair->content), 160);
    }

    /**
     * Cut at a word boundary. A mid-word cut ("… sowie d") reads as corrupted
     * input and invites the model to "complete" it.
     */
    protected function clip(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut, ' ,;:-').' …';
    }

    /**
     * Id of the oldest message still inside the look-back window, counted in
     * participant messages rather than rows so system rows cannot skew it.
     */
    protected function cutoffMessageId(int $maxAge): int
    {
        $ids = $this->project->messages()
            ->whereNotNull('expert_id')
            ->orderByDesc('id')
            ->limit($maxAge)
            ->pluck('id');

        return (int) ($ids->last() ?? 0);
    }
}
