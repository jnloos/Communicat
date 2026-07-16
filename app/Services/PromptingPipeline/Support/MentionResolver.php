<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * Deterministic @-mention matching for the pipeline shortcut: when the latest
 * (unanswered) message comes from a user and contains "@Name" of a contributing
 * expert, that expert answers directly — no route/select LLM calls needed.
 *
 * Matching is intentionally strict (no fuzzy matching): the mention picker in
 * the chat input inserts the literal full name, so we match "@Full Name" first
 * and fall back to "@Firstname" only when the first name is unambiguous among
 * the project's contributing experts.
 */
class MentionResolver
{
    /**
     * @param  Collection<int, Expert>  $experts  the project's contributing experts
     * @return Expert[] mentioned experts, in order of first appearance
     */
    public function match(?Message $latestMessage, Collection $experts): array
    {
        if ($latestMessage === null || $latestMessage->user_id === null) {
            return [];
        }

        $content = $latestMessage->content;
        if (! str_contains($content, '@')) {
            return [];
        }

        $firstNameCounts = $experts
            ->map(fn (Expert $e) => mb_strtolower($this->firstName($e->name)))
            ->countBy()
            ->all();

        $hits = [];
        foreach ($experts as $expert) {
            $offset = $this->earliestMentionOffset($content, $expert, $firstNameCounts);
            if ($offset !== null) {
                $hits[$expert->id] = ['expert' => $expert, 'offset' => $offset];
            }
        }

        usort($hits, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return array_map(fn (array $hit) => $hit['expert'], array_values($hits));
    }

    /**
     * Byte offset of the earliest "@Full Name" (or unambiguous "@Firstname")
     * mention of this expert, or null when the message doesn't mention them.
     *
     * @param  array<string, int>  $firstNameCounts  lowercased first name → count
     */
    protected function earliestMentionOffset(string $content, Expert $expert, array $firstNameCounts): ?int
    {
        $offsets = [];

        if (preg_match($this->pattern($expert->name), $content, $m, PREG_OFFSET_CAPTURE)) {
            $offsets[] = $m[0][1];
        }

        $firstName = $this->firstName($expert->name);
        if ($firstName !== $expert->name && ($firstNameCounts[mb_strtolower($firstName)] ?? 0) === 1) {
            if (preg_match($this->pattern($firstName), $content, $m, PREG_OFFSET_CAPTURE)) {
                $offsets[] = $m[0][1];
            }
        }

        return empty($offsets) ? null : min($offsets);
    }

    /**
     * "@Name" must start the message or follow whitespace, and must not be
     * followed by another letter (so "@Anna" doesn't match "@Annabell").
     */
    protected function pattern(string $name): string
    {
        return '/(?:^|(?<=\s))@'.preg_quote($name, '/').'(?!\p{L})/iu';
    }

    protected function firstName(string $name): string
    {
        $tokens = preg_split('/\s+/u', trim($name)) ?: [];

        return $tokens[0] ?? $name;
    }
}
