<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Project;
use App\Services\Text\MemoryFormatter;

/**
 * Keep a question anchored in the memory of the persona it was actually put to.
 *
 * Questions used to be recorded only by the asker: David's memory would hold
 * "Frage an Verena Albrecht: …" while Verena's memory said nothing at all — so
 * the addressee had no idea she had been asked, and the question quietly died.
 * Writing it into the addressee's `[OFFENE_FRAGEN]` is deterministic (no LLM),
 * so a question that was asked cannot be lost.
 *
 * Sibling of UserQuestionMemory, which does the same for the human's question.
 */
class OpenQuestionMemory
{
    /** Max characters of the question kept in memory. */
    protected const EXCERPT_LENGTH = 200;

    public function __construct(protected MemoryFormatter $formatter) {}

    /**
     * Record that $speaker asked $addressee something in $content.
     */
    public function record(Project $project, Expert $addressee, string $speakerName, string $content): void
    {
        $question = $this->closingQuestion($content);

        if ($question === null) {
            return;
        }

        $entry = 'Frage von '.$speakerName.': '.$question;

        $summary = $addressee->thoughtsAbout($project);
        $summary->content = $this->withQuestion((string) $summary->content, $entry);
        $summary->save();
    }

    /**
     * Drop every recorded question once the persona has taken the floor and
     * answered. The pair is closed at that point; leaving the entry in would
     * make the persona keep re-answering it.
     */
    public function clear(Project $project, Expert $addressee): void
    {
        $summary = $addressee->thoughtsAbout($project);
        $content = (string) $summary->content;

        if (! str_contains($content, 'Frage von ')) {
            return;
        }

        $sections = $this->sections($content);
        $sections['open_questions'] = array_values(array_filter(
            $sections['open_questions'] ?? [],
            fn (string $q) => ! str_starts_with($q, 'Frage von '),
        ));

        $summary->content = $this->rebuild($content, $sections);
        $summary->save();
    }

    /**
     * Append the entry to `[OFFENE_FRAGEN]`, skipping exact duplicates so a
     * repeated question does not stack up.
     */
    protected function withQuestion(string $content, string $entry): string
    {
        $sections = $this->sections($content);
        $questions = $sections['open_questions'] ?? [];

        if (in_array($entry, $questions, true)) {
            return $content;
        }

        $questions[] = $entry;
        $sections['open_questions'] = $questions;

        return $this->rebuild($content, $sections);
    }

    /**
     * Parse the memory body, keeping the user-question block out of the way so
     * the round-trip cannot drop it (MemoryFormatter does not know that marker).
     *
     * @return array<string, mixed>
     */
    protected function sections(string $content): array
    {
        return $this->formatter->parse(UserQuestionMemory::strip($content));
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    protected function rebuild(string $original, array $sections): string
    {
        $body = $this->formatter->render($sections);

        // Re-attach the user question block exactly as it was.
        $question = $this->userQuestion($original);

        return $question === null
            ? $body
            : UserQuestionMemory::upsert($body, $question);
    }

    protected function userQuestion(string $content): ?string
    {
        if (! UserQuestionMemory::contains($content)) {
            return null;
        }

        $stripped = UserQuestionMemory::strip($content);
        $block = trim(str_replace($stripped, '', $content));
        $question = trim(str_replace(UserQuestionMemory::MARKER, '', $block));

        return $question === '' ? null : $question;
    }

    /**
     * The last question in the contribution — the one the next turn is expected
     * to answer.
     */
    protected function closingQuestion(string $content): ?string
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($content)) ?: [];

        $questions = array_values(array_filter(
            array_map('trim', $parts),
            fn (string $s) => str_ends_with($s, '?'),
        ));

        if (empty($questions)) {
            return null;
        }

        return mb_substr(end($questions), 0, self::EXCERPT_LENGTH);
    }
}
