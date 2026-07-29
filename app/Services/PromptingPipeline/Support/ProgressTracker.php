<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Project;
use App\Services\Clients\OpenAIClient;

/**
 * Detects whether a discussion is making progress or circling, and drives it
 * toward closure. Two mechanisms, mixed:
 *
 *  - Deterministic (no LLM): a content-word fingerprint per turn compared by
 *    Jaccard overlap against recent turns. High overlap = "nothing new" and
 *    bumps a stagnation counter; genuinely new content resets it.
 *  - Periodic LLM closure check: every few turns (or immediately when the
 *    stagnation counter trips) the moderator judges whether the current point
 *    is resolved or the discussion is going in circles, and names the next move
 *    and the remaining open question. Its verdict advances the agenda by content
 *    instead of the blind turn counter.
 *
 * All state lives in project.settings so it survives across queued turns.
 */
class ProgressTracker
{
    /**
     * Only the project is required; the LLM client and prompt builder are
     * resolved lazily inside the closure check, so the deterministic paths
     * (recordTurn / non-due signals) never construct an OpenAIClient. That keeps
     * the common turn bookkeeping free of any API-key requirement.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /** Keep the ledgers bounded — old points fall off the end. */
    protected const LEDGER_KEEP = 12;

    /**
     * Called after a turn (from ModeratorService::updateState): fingerprint the
     * new turn, update the stagnation counter and the covered-points ledger, and
     * advance the closure-check interval counter. Returns the mutated settings.
     */
    public function recordTurn(array $settings, string $content, string $beitragsabsicht = ''): array
    {
        $window = max(1, (int) config('discussion.fingerprint_window', 6));
        $overlapThreshold = (float) config('discussion.fingerprint_overlap', 0.6);

        $fingerprint = $this->fingerprint($content);
        $recent = $settings['recent_fingerprints'] ?? [];

        $maxOverlap = 0.0;
        foreach ($recent as $prev) {
            $maxOverlap = max($maxOverlap, $this->jaccard($fingerprint, (array) $prev));
        }

        if (! empty($fingerprint) && $maxOverlap >= $overlapThreshold) {
            $settings['stagnation_counter'] = (int) ($settings['stagnation_counter'] ?? 0) + 1;
        } else {
            $settings['stagnation_counter'] = 0;
        }

        array_unshift($recent, $fingerprint);
        $settings['recent_fingerprints'] = array_slice($recent, 0, $window);

        // Ledger of covered points — a short label from the contribution intent
        // (preferred, it names the move) or else the visible content.
        $label = $this->shortLabel($beitragsabsicht !== '' ? $beitragsabsicht : $content);
        if ($label !== '') {
            $covered = $settings['covered_points'] ?? [];
            $covered[] = $label;
            $settings['covered_points'] = array_slice($covered, -self::LEDGER_KEEP);
        }

        $settings['turns_since_closure_check'] = (int) ($settings['turns_since_closure_check'] ?? 0) + 1;

        // Topic standing time: how many turns the discussion has stayed on the
        // current topic without a registered advance. Only reset when the tracker
        // actually detects a topic change/closure (see signals()). This is the
        // "the topic hasn't changed in a long time" signal.
        $settings['turns_on_topic'] = (int) ($settings['turns_on_topic'] ?? 0) + 1;

        return $settings;
    }

    /**
     * Record the personas' own verdicts on whether the current topic is done,
     * gathered from the THINK outputs of the experts that thought this turn.
     * Votes accumulate across turns within the current topic epoch (a "done"
     * expert stays counted until the topic advances); an expert that later votes
     * "offen" withdraws their vote. Reset happens in signals() on advance.
     *
     * @param  array<int, bool>  $votes  expert id → topic_done
     */
    public function recordTopicVotes(array $votes): void
    {
        if (empty($votes)) {
            return;
        }

        $settings = $this->project->settings ?? [];
        $current = $settings['topic_done_votes'] ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        foreach ($votes as $expertId => $done) {
            if ($done) {
                $current[(int) $expertId] = true;
            } else {
                // Changed their mind / still has something → withdraw the vote.
                unset($current[(int) $expertId]);
            }
        }

        $settings['topic_done_votes'] = $current;
        $this->project->settings = $settings;
        $this->project->save();
    }

    /**
     * Called at the start of a turn (from RunOrchestratorInstructions): expose
     * the progress ledgers and, when a check is due, run the LLM closure check
     * and persist its verdict. Returns the advisory signals for moderationContext.
     *
     * @return array{stagnation:int, covered_points:array, resolved_points:array, open_question:?string, next_move:?string, closure_due:bool, point_resolved:bool, going_in_circles:bool, zwischenergebnis:?string, topic_turns:int, topic_stale:bool, persona_done:bool, persona_done_votes:int}
     */
    public function signals(): array
    {
        $settings = $this->project->settings ?? [];

        $signals = [
            'stagnation' => (int) ($settings['stagnation_counter'] ?? 0),
            'covered_points' => $settings['covered_points'] ?? [],
            'resolved_points' => $settings['resolved_points'] ?? [],
            'open_question' => $settings['open_question'] ?? null,
            'next_move' => $settings['next_move'] ?? null,
            'closure_due' => false,
            'point_resolved' => false,
            'going_in_circles' => false,
            'zwischenergebnis' => null,
            'topic_turns' => (int) ($settings['turns_on_topic'] ?? 0),
            'topic_stale' => false,
            'persona_done' => false,
            'persona_done_votes' => 0,
        ];

        if (! config('discussion.closure_check', true)) {
            return $signals;
        }

        $interval = max(1, (int) config('discussion.closure_check_interval', 4));
        $threshold = max(1, (int) config('discussion.stagnation_threshold', 3));
        $staleThreshold = max(1, (int) config('discussion.topic_stale_threshold', 6));
        $turnsSince = (int) ($settings['turns_since_closure_check'] ?? 0);

        // Stronger "topic hasn't changed in a long time" signal: the discussion
        // has stayed on the same topic for too many turns without any registered
        // advance. Forces convergence even if the LLM keeps saying "vertiefen".
        $topicStale = $signals['topic_turns'] >= $staleThreshold;

        // "Topic done according to the personas": a quorum of the panel flagged
        // the current point as exhausted via THINK's THEMA_STATUS.
        [$personaDone, $doneVotes] = $this->personaConsensus($settings);

        $signals['topic_stale'] = $topicStale;
        $signals['persona_done'] = $personaDone;
        $signals['persona_done_votes'] = $doneVotes;

        $due = $signals['stagnation'] >= $threshold
            || $turnsSince >= $interval
            || $topicStale
            || $personaDone;

        if (! $due) {
            return $signals;
        }

        // Reset the interval counter now, whether or not the check yields a
        // usable verdict, so a malformed response doesn't re-fire every turn.
        $settings['turns_since_closure_check'] = 0;

        $result = $this->runClosureCheck();

        // The two deterministic signals force an advance regardless of the LLM
        // verdict: a stale topic maps to "circling" (drive to convergence), a
        // persona consensus maps to "resolved" (the panel considers it done).
        $forceCircling = $topicStale;
        $forceResolved = $personaDone;

        if ($result !== null) {
            $signals['closure_due'] = true;
            $signals['point_resolved'] = (bool) ($result['point_resolved'] ?? false) || $forceResolved;
            $signals['going_in_circles'] = (bool) ($result['going_in_circles'] ?? false) || $forceCircling;
            $signals['next_move'] = $this->cleanString($result['next_move'] ?? null);
            $signals['open_question'] = $this->cleanString($result['open_question'] ?? null);
            $signals['zwischenergebnis'] = $this->cleanString($result['zwischenergebnis'] ?? null);

            $settings['open_question'] = $signals['open_question'];
            $settings['next_move'] = $signals['next_move'];
        } elseif ($forceResolved || $forceCircling) {
            // No usable LLM verdict, but a deterministic signal still fired:
            // advance anyway so the stronger detection is never swallowed by a
            // malformed response.
            $signals['closure_due'] = true;
            $signals['point_resolved'] = $forceResolved;
            $signals['going_in_circles'] = $forceCircling;
        }

        // A resolved point or a detected circle advances the agenda by content
        // (consumed by ModeratorService::advanceAgenda), resets stagnation, and
        // opens a fresh topic epoch (streak + persona votes cleared).
        if ($signals['point_resolved'] || $signals['going_in_circles']) {
            $settings['closure_advance'] = true;
            $settings['stagnation_counter'] = 0;
            $settings['turns_on_topic'] = 0;
            $settings['topic_done_votes'] = [];
        }

        if ($signals['point_resolved'] && $signals['zwischenergebnis'] !== null) {
            $resolved = $settings['resolved_points'] ?? [];
            $resolved[] = $this->shortLabel($signals['zwischenergebnis']);
            $settings['resolved_points'] = array_slice($resolved, -self::LEDGER_KEEP);
            $signals['resolved_points'] = $settings['resolved_points'];
        }

        $this->project->settings = $settings;
        $this->project->save();

        return $signals;
    }

    /**
     * Whether a quorum of the contributing experts flagged the current topic as
     * done. Votes from experts no longer contributing are ignored. Returns the
     * boolean verdict and the raw done-vote count (for the advisory signal).
     *
     * @return array{0: bool, 1: int}
     */
    protected function personaConsensus(array $settings): array
    {
        if (! config('discussion.persona_topic_done', true)) {
            return [false, 0];
        }

        $votes = $settings['topic_done_votes'] ?? [];
        if (! is_array($votes) || empty($votes)) {
            return [false, 0];
        }

        $contributorIds = $this->project->contributingExperts()->pluck('id')->all();

        $doneVotes = 0;
        foreach ($votes as $expertId => $done) {
            if ($done && in_array((int) $expertId, $contributorIds, true)) {
                $doneVotes++;
            }
        }

        $panel = max(1, count($contributorIds));
        $fraction = (float) config('discussion.topic_done_quorum', 0.6);
        $needed = max(1, (int) ceil($panel * $fraction));

        return [$doneVotes >= $needed, $doneVotes];
    }

    /**
     * Ask the moderator LLM whether the current point is resolved / circular and
     * what the next move + open question is. Returns the decoded JSON or null.
     */
    protected function runClosureCheck(): ?array
    {
        $prompt = app(PromptBuilder::class)->moderatorClosure($this->project);
        $response = app(OpenAIClient::class)->sendFast($prompt, 'moderator:closure');

        return $this->parseJson($response);
    }

    /**
     * Reduce a turn to its set of significant content words (lowercased, no
     * short words, no stopwords) — the basis for repetition comparison.
     *
     * @return string[]
     */
    public function fingerprint(string $content): array
    {
        $content = mb_strtolower(strip_tags($content));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stop = $this->stopwords();
        $significant = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 4 || isset($stop[$token])) {
                continue;
            }
            $significant[$token] = true;
        }

        return array_keys($significant);
    }

    /**
     * Jaccard overlap of two word sets: |A ∩ B| / |A ∪ B|, in [0, 1].
     *
     * @param  string[]  $a
     * @param  string[]  $b
     */
    public function jaccard(array $a, array $b): float
    {
        if (empty($a) && empty($b)) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /** First few words of a text, trimmed to a compact ledger label. */
    protected function shortLabel(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($text === '') {
            return '';
        }

        $words = array_slice(preg_split('/\s+/u', $text) ?: [], 0, 12);

        return mb_substr(implode(' ', $words), 0, 90);
    }

    protected function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Parse JSON from a response that may be wrapped in a markdown code block.
     */
    protected function parseJson(string $response): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($response));
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        $decoded = json_decode(trim($cleaned), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Modest German stopword set — enough to keep the fingerprint focused on
     * content words without pulling in a full NLP dependency.
     *
     * @return array<string, true>
     */
    protected function stopwords(): array
    {
        static $stop = null;
        if ($stop !== null) {
            return $stop;
        }

        $words = [
            'aber', 'also', 'auch', 'auf', 'aus', 'bei', 'bin', 'bis', 'dann', 'dass',
            'dein', 'denn', 'der', 'die', 'das', 'dem', 'den', 'des', 'dich', 'doch',
            'dort', 'durch', 'ein', 'eine', 'einen', 'einer', 'eines', 'einem', 'etwa',
            'euch', 'euer', 'für', 'ganz', 'gar', 'gegen', 'gewesen', 'hab', 'habe',
            'haben', 'hat', 'hatte', 'hier', 'hin', 'ich', 'ihr', 'ihre', 'immer', 'ist',
            'jede', 'jeder', 'jetzt', 'kann', 'kein', 'keine', 'können', 'machen', 'man',
            'mehr', 'mein', 'muss', 'nach', 'nicht', 'noch', 'nur', 'oder', 'schon',
            'sehr', 'sein', 'seine', 'sich', 'sind', 'soll', 'sollte', 'sondern',
            'über', 'und', 'uns', 'unser', 'viel', 'vom', 'von', 'vor', 'wann', 'war',
            'waren', 'was', 'weil', 'weiter', 'wenn', 'werden', 'wie', 'wir', 'wird',
            'wieder', 'wird', 'wohl', 'zum', 'zur', 'zwar', 'zwischen',
        ];

        return $stop = array_fill_keys($words, true);
    }
}
