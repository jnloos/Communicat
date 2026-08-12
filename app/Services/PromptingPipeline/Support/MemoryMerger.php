<?php

namespace App\Services\PromptingPipeline\Support;

use App\Services\Text\MemoryFormatter;

/**
 * Fold a fresh GEDÄCHTNIS-UPDATE into an expert's existing memory instead of
 * replacing it.
 *
 * THINK used to overwrite the memory wholesale, so any participant block the
 * model forgot to repeat was permanently deleted. Measured on the logged runs
 * whose project is known: 2 of 91 consecutive THINK pairs (2.2%) dropped at
 * least one block — uncommon, but one of them wiped three participants from one
 * expert's memory in a single step. A persona that no longer knows who believes
 * what produces exactly the vague, disconnected turns that make the discussion
 * feel incoherent, and nothing restores the lost blocks afterwards.
 *
 * Merge rule: whatever the new block states wins for that section; sections it
 * omits are carried over from the old memory. Forgetting is therefore no longer
 * destructive — but an explicit update always is authoritative.
 *
 * The `[AKTUELLE_NUTZERFRAGE]` block is deliberately stripped here: it is owned
 * by the project (projects.settings['current_user_question']) and re-applied by
 * the caller via UserQuestionMemory, so per-expert copies cannot drift apart.
 */
class MemoryMerger
{
    public function __construct(protected MemoryFormatter $formatter) {}

    /**
     * @param  string[]  $allowedTokens  when non-empty, participant blocks for
     *                                   tokens outside this list are dropped
     *                                   (contributors who left the project)
     */
    public function merge(?string $existing, string $incoming, array $allowedTokens = []): string
    {
        $incomingBody = UserQuestionMemory::strip($incoming);
        $existingBody = UserQuestionMemory::strip((string) $existing);

        if (trim($incomingBody) === '') {
            return trim($existingBody);
        }

        $new = $this->formatter->parse($incomingBody);
        $old = $this->formatter->parse($existingBody);

        // An unstructured update cannot be merged section-wise. Keep it whole
        // rather than silently discarding what the persona just wrote.
        if (! $new['structured']) {
            return trim($incomingBody);
        }

        if (! $old['structured']) {
            $old = ['user' => null, 'users' => [], 'experts' => [], 'open_questions' => [], 'state' => null];
        }

        $merged = [
            'user' => $new['user'] ?? $old['user'] ?? null,
            'users' => array_merge($old['users'] ?? [], $new['users'] ?? []),
            'experts' => array_merge($old['experts'] ?? [], $new['experts'] ?? []),
            // parse() cannot tell "section absent" from "section says keine",
            // so ask the raw text which one it was.
            'open_questions' => $this->sectionPresent($incomingBody, '[OFFENE_FRAGEN]')
                ? $new['open_questions']
                : ($old['open_questions'] ?? []),
            'state' => $this->sectionPresent($incomingBody, '[STAND]')
                ? $new['state']
                : ($old['state'] ?? null),
        ];

        if (! empty($allowedTokens)) {
            $merged['users'] = $this->restrict($merged['users'], $allowedTokens);
            $merged['experts'] = $this->restrict($merged['experts'], $allowedTokens);
        }

        return $this->formatter->render($merged);
    }

    /**
     * Drop participant blocks whose token is no longer part of the project.
     *
     * Only token-keyed blocks are pruned. Legacy memories key their blocks by
     * display name (`[EXPERTE: Alice]`); those cannot be matched against the
     * token list, and dropping them would delete knowledge on a guess — they
     * age out on their own once the persona writes a token-based update.
     *
     * @param  array<string, string>  $blocks
     * @param  string[]  $allowedTokens
     * @return array<string, string>
     */
    protected function restrict(array $blocks, array $allowedTokens): array
    {
        return array_filter(
            $blocks,
            fn (string $key) => ! preg_match('/^[EU]\d+$/', $key) || in_array($key, $allowedTokens, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function sectionPresent(string $body, string $marker): bool
    {
        return (bool) preg_match('/^\s*'.preg_quote($marker, '/').'\s*$/mu', $body);
    }
}
