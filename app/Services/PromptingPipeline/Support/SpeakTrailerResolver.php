<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Services\PromptingPipeline\Data\Directive;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile a SPEAK result's control trailer with what the visible text actually
 * does. The trailer is unreliable in practice: across the logged production runs
 * it was missing entirely in ~20% of the most recent turns, and in a further set
 * of turns it said `ADRESSAT: none` while the prose asked a named expert a
 * direct question. Both cases leave an adjacency pair open with nobody on the
 * hook, so the addressed expert never answers and the round falls back to
 * pestering the user.
 *
 * This resolver is deterministic — no extra LLM call. It only ever *adds* an
 * addressee the text already implies; it never overrides a trailer that named
 * one.
 *
 * Deliberately conservative: prose recovery fires only when the contribution
 * actually asks a question. A name mentioned in passing ("David hat recht")
 * stays a plenum turn, because inventing an obligation to reply where the text
 * implies none would distort the very adjacency-pair statistics this is meant
 * to make trustworthy.
 */
class SpeakTrailerResolver
{
    /** Addressee came from the agent's own STEUERUNG trailer. */
    public const SOURCE_TRAILER = 'trailer';

    /** Addressee was recovered from the visible prose. */
    public const SOURCE_PROSE = 'prose_fallback';

    /** No addressee — the turn speaks to the plenum. */
    public const SOURCE_NONE = 'none';

    public function __construct(protected MentionResolver $mentions) {}

    /**
     * @param  array{content: string, adjacency_pair_type: ?string, adjacency_partner_token: ?string}  $speakResult
     * @return array{
     *     content: string,
     *     adjacency_pair_type: ?string,
     *     adjacency_partner_token: ?string,
     *     resolver_source: string,
     *     ends_with_question: bool,
     *     trailer_present: bool,
     * }
     */
    public function resolve(array $speakResult, Project $project, Expert $speaker, ?Directive $directive = null): array
    {
        if ($this->mustNotAddressUser($directive)) {
            $speakResult['content'] = $this->stripUserVocative(
                (string) ($speakResult['content'] ?? ''),
                $project,
            );
        }

        return $this->reconcile(
            $speakResult,
            $project->contributingExperts()
                ->reject(fn (Expert $e) => $e->id === $speaker->id)
                ->values(),
        );
    }

    /**
     * True when this turn belongs to the expert round: no hand-off, no pending
     * user message to answer. Speaking to the human is then off-limits.
     */
    protected function mustNotAddressUser(?Directive $directive): bool
    {
        return $directive !== null
            && ! $directive->handBackToUser
            && empty($directive->pendingUserName);
    }

    /**
     * Remove a user vocative the SPEAK prompt already forbade.
     *
     * The prompt says plainly "Erwähne den Nutzernamen in diesem Beitrag
     * überhaupt nicht", and the model still opens with "admin, aus
     * architektonischer Sicht …" or tacks ", User01." onto the end. That is what
     * makes the user feel addressed when nobody chose them, so it gets a
     * deterministic net rather than another prompt rule.
     *
     * Narrow on purpose: only an exact participant name in a leading or trailing
     * vocative position is touched. A name used mid-sentence is left alone —
     * quoting the human is legitimate.
     */
    protected function stripUserVocative(string $content, Project $project): string
    {
        $names = $project->users()->get()
            ->push($project->owner)
            ->filter()
            ->unique('id')
            ->pluck('name')
            ->filter()
            ->all();

        $cleaned = $content;

        foreach ($names as $name) {
            $quoted = preg_quote((string) $name, '/');

            // "Name, …" / "Name: …" at the very start.
            $cleaned = preg_replace('/^\s*'.$quoted.'\s*[,:]\s*/iu', '', $cleaned) ?? $cleaned;

            // "…, Name." / "… Name." at the very end.
            $cleaned = preg_replace('/[,;]?\s*'.$quoted.'\s*([.!?])?\s*$/iu', '$1', $cleaned) ?? $cleaned;
        }

        $cleaned = trim($cleaned);

        if ($cleaned !== trim($content)) {
            Log::info('Stripped user vocative from an expert turn', [
                'project_id' => $project->id,
                'before_tail' => mb_substr(trim($content), -80),
            ]);
        }

        // Never hand back an empty contribution — if stripping ate everything,
        // the original is the lesser evil.
        return $cleaned === '' ? trim($content) : $cleaned;
    }

    /**
     * The reconciliation itself, expressed over the peers the speaker could
     * have addressed. Split out from resolve() so replay tooling can run it
     * against a roster rebuilt from recorded prompts, with no project row.
     *
     * @param  array{content: string, adjacency_pair_type: ?string, adjacency_partner_token: ?string}  $speakResult
     * @param  Collection<int, Expert>  $peers
     * @return array{
     *     content: string,
     *     adjacency_pair_type: ?string,
     *     adjacency_partner_token: ?string,
     *     resolver_source: string,
     *     ends_with_question: bool,
     *     trailer_present: bool,
     * }
     */
    public function reconcile(array $speakResult, Collection $peers): array
    {
        $content = trim((string) ($speakResult['content'] ?? ''));
        $token = $speakResult['adjacency_partner_token'] ?? null;
        $pairType = $speakResult['adjacency_pair_type'] ?? null;

        $endsWithQuestion = str_ends_with($content, '?');
        $trailerPresent = $token !== null || $pairType !== null;

        $source = $token !== null ? self::SOURCE_TRAILER : self::SOURCE_NONE;

        if ($token === null) {
            $recovered = $this->addresseeFromProse($content, $peers);

            if ($recovered !== null) {
                $token = $recovered->promptId;
                $source = self::SOURCE_PROSE;
            }
        }

        return [
            'content' => $content,
            'adjacency_pair_type' => $this->pairType($pairType, $token, $endsWithQuestion),
            'adjacency_partner_token' => $token,
            'resolver_source' => $source,
            'ends_with_question' => $endsWithQuestion,
            'trailer_present' => $trailerPresent,
        ];
    }

    /**
     * Recover the addressee from the prose. Questions are searched last-first:
     * the closing question is the one the next turn is expected to answer.
     * A question naming two experts stays unresolved — the "exactly one
     * addressee" rule the SPEAK prompt enforces must not be worked around here.
     *
     * @param  Collection<int, Expert>  $peers
     */
    protected function addresseeFromProse(string $content, Collection $peers): ?Expert
    {
        if (! str_contains($content, '?')) {
            return null;
        }

        if ($peers->isEmpty()) {
            return null;
        }

        foreach ($this->questionSentences($content) as $sentence) {
            $hits = $this->mentions->matchNames($sentence, $peers);

            if (count($hits) === 1) {
                return $hits[0];
            }

            // Two names in one question: ambiguous by design, stop here rather
            // than guessing — an earlier sentence is not the open pair.
            if (count($hits) > 1) {
                return null;
            }
        }

        // The question itself names nobody (e.g. "Wie siehst du das?" after
        // "Das trifft Beates Punkt."). Fall back to the whole contribution, but
        // only when it is unambiguous.
        $hits = $this->mentions->matchNames($content, $peers);

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Sentences ending in a question mark, closing one first.
     *
     * @return string[]
     */
    protected function questionSentences(string $content): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $content) ?: [];

        $questions = array_values(array_filter(
            array_map('trim', $parts),
            fn (string $s) => str_ends_with($s, '?'),
        ));

        return array_reverse($questions);
    }

    /**
     * Keep a valid pair type from the trailer; otherwise derive one that matches
     * what the text does, so a missing trailer no longer degrades every turn to
     * the generic "Beitrag→Diskussion".
     */
    protected function pairType(?string $fromTrailer, ?string $token, bool $endsWithQuestion): string
    {
        if ($fromTrailer !== null) {
            return $fromTrailer;
        }

        if ($token !== null) {
            return $endsWithQuestion
                ? Message::PAIR_FRAGE_ANTWORT
                : Message::PAIR_ANSPRACHE_REAKTION;
        }

        return Message::PAIR_BEITRAG_DISKUSSION;
    }
}
