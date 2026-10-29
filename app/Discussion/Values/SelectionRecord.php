<?php

namespace App\Discussion\Values;

use App\Models\Expert;

/**
 * A turn's selection as stored in job_logs.selection, and the one place that
 * knows its shape: the top-level keys (selector, speaker_id, signals,
 * reasoning, tie_broken) and the signals each selector writes --
 *
 *   RoundRobinSelector    seat
 *   HighestBidSelector    bids (token => priority), fallbacks (tokens whose bid was unreadable)
 *   OrchestratorSelector  token, fallback
 *   JudgeSelector         token, fallback
 *   BlindJudgeSelector    label, order (letter => expert id), fallback
 *   JevSpeakerSelector    token, fallback + the Jev pick signals
 *   JevJudgeSelector      token, fallback + the Jev pick signals
 *   JevBlindJudgeSelector label, order, fallback + the Jev pick signals (probabilities by letter)
 *
 * The Jev pick signals (jevPickSignals, jevBlindPickSignals), every one prefixed
 * jev_ so they can never be mistaken for a team model's: jev_kind ("pick"),
 * jev_asked (a request was made; false for a lone candidate or when no draft was
 * handed in), jev_probabilities (option or letter => probability, empty when Jev
 * gave none or was not asked), jev_confidence, jev_model (what the response
 * names) and jev_latency_ms.
 *
 * A Score cell whose bids come from Jev (the JevBids stage puts them in the
 * payload, HighestBidSelector or JevBidSelector reads them) carries, next to the
 * ordinary bids and fallbacks -- which then hold JEV's rounded bids and the agents
 * whose Jev bid was unreadable -- the signals of jevBidSignals: jev_kind ("bid"),
 * jev_bid_resolution, jev_own_bids (what the team model bid itself, token =>
 * priority or null), jev_scores (the unrounded expected level behind each Jev
 * bid, null when unreadable), jev_confidences, jev_models (what each response
 * names) and jev_latencies_ms. JevBidSelector, used when the resolution is
 * unrounded, adds jev_tie_pool (how many agents shared the top score).
 *
 * It wraps the stored array rather than mapping it onto fields, because old
 * runs and imported bundles may carry keys or malformed values no current
 * selector writes, and toArray() must hand every one of them back unchanged.
 */
final readonly class SelectionRecord
{
    private const KIND_PICK = 'pick';

    private const KIND_BID = 'bid';

    /** Signals that are maps keyed by expert token (or, for probabilities of a blind pick, by letter, which a renumbering leaves alone). */
    private const TOKEN_KEYED = ['bids', 'jev_probabilities', 'jev_own_bids', 'jev_scores', 'jev_confidences', 'jev_models', 'jev_latencies_ms'];

    private function __construct(private array $data) {}

    public static function fromArray(?array $selection): self
    {
        return new self($selection ?? []);
    }

    /** What a selector's Selection stores; Selection::toArray() goes through here. */
    public static function written(string $selector, int $speakerId, array $signals, string $reasoning, bool $tieBroken): self
    {
        return new self([
            'selector' => $selector,
            'speaker_id' => $speakerId,
            'signals' => $signals,
            'reasoning' => $reasoning,
            'tie_broken' => $tieBroken,
        ]);
    }

    /** @param  array<string, int>  $bids  token => priority, an unreadable bid already imputed */
    public static function bidSignals(array $bids, array $fallbacks): array
    {
        return ['bids' => $bids, 'fallbacks' => $fallbacks];
    }

    /** The orchestrator's or the judge's pick, as the token the model answered with. */
    public static function pickSignals(?string $token, bool $fallback): array
    {
        return ['token' => $token, 'fallback' => $fallback];
    }

    /** @param  array<string, int>  $order  letter => expert id */
    public static function blindPickSignals(?string $label, array $order, bool $fallback): array
    {
        return ['label' => $label, 'order' => $order, 'fallback' => $fallback];
    }

    /**
     * Jev's pick as orchestrator or judge: the pick signals plus what Jev said.
     *
     * @return array<string, mixed>
     */
    public static function jevPickSignals(JevPick $pick): array
    {
        return self::pickSignals($pick->key, $pick->fallback) + self::jevAnswer($pick);
    }

    /**
     * Jev's pick as blind judge.
     *
     * @param  array<string, int>  $order  letter => expert id
     * @return array<string, mixed>
     */
    public static function jevBlindPickSignals(JevPick $pick, array $order): array
    {
        return self::blindPickSignals($pick->key, $order, $pick->fallback) + self::jevAnswer($pick);
    }

    /**
     * What Jev said when it produced the bids of a Score turn, to sit next to
     * bidSignals() (which then holds Jev's integer bids).
     *
     * @param  list<JevAgentBid>  $bids
     * @return array<string, mixed>
     */
    public static function jevBidSignals(array $bids, BidResolution $resolution): array
    {
        $byToken = fn (callable $of) => array_combine(array_map(fn (JevAgentBid $bid) => $bid->token, $bids), array_map($of, $bids));

        return [
            'jev_kind' => self::KIND_BID,
            'jev_bid_resolution' => $resolution->value,
            'jev_own_bids' => $byToken(fn (JevAgentBid $bid) => $bid->own),
            'jev_scores' => $byToken(fn (JevAgentBid $bid) => $bid->jev->value),
            'jev_confidences' => $byToken(fn (JevAgentBid $bid) => $bid->jev->confidence),
            'jev_models' => $byToken(fn (JevAgentBid $bid) => $bid->jev->model),
            'jev_latencies_ms' => $byToken(fn (JevAgentBid $bid) => $bid->jev->latencyMs),
        ];
    }

    /**
     * The bids of a Jev Score turn when the floor goes to the highest UNROUNDED
     * Jev score (JevBidSelector): the ordinary bid signals plus how many agents
     * shared the top score, which is the pool a tie was drawn from.
     *
     * @param  array<string, int>  $bids  token => rounded bid, an unreadable one already imputed
     * @return array<string, mixed>
     */
    public static function jevBidPickSignals(array $bids, array $fallbacks, int $tiePool): array
    {
        return self::bidSignals($bids, $fallbacks) + ['jev_tie_pool' => $tiePool];
    }

    /**
     * The unrounded Jev scores a stage left in the signals, token => score (null
     * when unreadable); empty when there are none.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, ?float>
     */
    public static function jevScoresIn(array $signals): array
    {
        $scores = $signals['jev_scores'] ?? null;

        return is_array($scores) ? array_map(self::number(...), $scores) : [];
    }

    public static function seatSignals(int $seat): array
    {
        return ['seat' => $seat];
    }

    /** @return array<string, mixed> */
    private static function jevAnswer(JevPick $pick): array
    {
        return [
            'jev_kind' => self::KIND_PICK,
            'jev_asked' => $pick->asked,
            'jev_probabilities' => $pick->probabilities,
            'jev_confidence' => $pick->confidence,
            'jev_model' => $pick->model,
            'jev_latency_ms' => $pick->latencyMs,
        ];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function selector(): ?string
    {
        return self::string($this->data['selector'] ?? null);
    }

    public function speakerId(): ?int
    {
        $speaker = $this->data['speaker_id'] ?? null;

        return $speaker === null ? null : (int) $speaker;
    }

    public function reasoning(): ?string
    {
        return self::string($this->data['reasoning'] ?? null);
    }

    public function tieBroken(): ?bool
    {
        $tieBroken = $this->data['tie_broken'] ?? null;

        return $tieBroken === null ? null : (bool) $tieBroken;
    }

    /** The blind judge's letter. */
    public function label(): ?string
    {
        return self::string($this->signals()['label'] ?? null);
    }

    /**
     * Whether the selector fell back: for Orchestrator and the judges the
     * model's pick was unreadable, for Score any agent's bid was (not
     * necessarily the speaker's). Null for a selector that records neither
     * (round robin).
     */
    public function fallback(): ?bool
    {
        $signals = $this->signals();

        if (array_key_exists('fallback', $signals)) {
            return $signals['fallback'] === null ? null : (bool) $signals['fallback'];
        }

        return array_key_exists('fallbacks', $signals) ? $signals['fallbacks'] !== [] : null;
    }

    /**
     * The probability a Jev pick (orchestrator, judge, blind judge) gave the
     * expert who actually spoke; for the blind judge, the one it gave the
     * speaker's letter. Null for anything that is not a Jev pick, when Jev was not
     * asked or gave nothing readable, or when there is no expert.
     */
    public function speakerProbability(?int $expertId): ?float
    {
        $signals = $this->signals();

        if ($expertId === null || ($signals['jev_kind'] ?? null) !== self::KIND_PICK || ! is_array($signals['jev_probabilities'] ?? null)) {
            return null;
        }

        $key = is_array($signals['order'] ?? null) ? self::letterOf($expertId, $signals['order']) : Expert::tokenFor($expertId);

        return $key === null ? null : self::number($signals['jev_probabilities'][$key] ?? null);
    }

    /**
     * The unrounded expected level (1 to 5) Jev gave the expert who actually
     * spoke when Jev produced the bids of a Score turn. Null for anything else,
     * when Jev gave nothing readable, or when there is no expert.
     */
    public function speakerJevScore(?int $expertId): ?float
    {
        $signals = $this->signals();

        if ($expertId === null || ($signals['jev_kind'] ?? null) !== self::KIND_BID) {
            return null;
        }

        return self::jevScoresIn($signals)[Expert::tokenFor($expertId)] ?? null;
    }

    /**
     * Every Jev request of this turn failed or was unreadable: for a pick, the one
     * request; for Jev bids, all of them. A turn in which Jev was not asked (a lone
     * candidate, no draft) or answered even once is not an outage turn, and neither
     * is anything that is not a Jev turn.
     */
    public function everyJevCallFellBack(): bool
    {
        $signals = $this->signals();

        return match ($signals['jev_kind'] ?? null) {
            self::KIND_PICK => ($signals['jev_asked'] ?? false) === true && ($signals['fallback'] ?? false) === true,
            self::KIND_BID => ($scores = self::jevScoresIn($signals)) !== [] && array_filter($scores, fn (?float $score) => $score !== null) === [],
            default => false,
        };
    }

    /**
     * An expert's bid in a Score turn. An unreadable bid is stored as the
     * lowest priority with its token listed in fallbacks: that 1 was never
     * said, so the bid is null and flagged as imputed. Not a Score turn, or
     * no expert: both null.
     *
     * @return array{bid: int|null, imputed: bool|null}
     */
    public function bidOf(?int $expertId): array
    {
        $signals = $this->signals();

        if (! is_array($signals['bids'] ?? null) || $expertId === null) {
            return ['bid' => null, 'imputed' => null];
        }

        $token = Expert::tokenFor($expertId);

        if (in_array($token, $signals['fallbacks'] ?? [], true)) {
            return ['bid' => null, 'imputed' => true];
        }

        return ['bid' => $signals['bids'][$token] ?? null, 'imputed' => false];
    }

    /**
     * The same record with every expert id it holds passed through $map:
     * speaker_id, the keys of signals.bids, the tokens in signals.fallbacks
     * and signals.token, the values of signals.order. Anything that is not an
     * id or an E-token, and any id $map leaves alone, keeps its exact value
     * and type. Texts (reasoning) are never rewritten.
     *
     * @param  callable(int): int  $map
     */
    public function mapExpertIds(callable $map): self
    {
        $data = $this->data;
        $id = fn (mixed $old) => self::mapId($old, $map);
        $token = fn (mixed $old) => self::mapToken($old, $map);

        if (array_key_exists('speaker_id', $data)) {
            $data['speaker_id'] = $id($data['speaker_id']);
        }

        if (! is_array($data['signals'] ?? null)) {
            return new self($data);
        }

        $signals = $data['signals'];

        foreach (self::TOKEN_KEYED as $key) {
            if (is_array($signals[$key] ?? null)) {
                $renamed = [];

                foreach ($signals[$key] as $holder => $value) {
                    $renamed[$token((string) $holder)] = $value;
                }

                $signals[$key] = $renamed;
            }
        }

        if (is_array($signals['fallbacks'] ?? null)) {
            $signals['fallbacks'] = array_map($token, $signals['fallbacks']);
        }

        if (array_key_exists('token', $signals)) {
            $signals['token'] = $token($signals['token']);
        }

        if (is_array($signals['order'] ?? null)) {
            $signals['order'] = array_map($id, $signals['order']);
        }

        $data['signals'] = $signals;

        return new self($data);
    }

    private function signals(): array
    {
        $signals = $this->data['signals'] ?? null;

        return is_array($signals) ? $signals : [];
    }

    /** A malformed value from an old run or a bundle reads as absent instead of throwing. */
    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    /** The letter an expert's draft stood under, from the order signal (letter => expert id). */
    private static function letterOf(int $expertId, array $order): ?string
    {
        foreach ($order as $letter => $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === $expertId) {
                return (string) $letter;
            }
        }

        return null;
    }

    private static function mapId(mixed $old, callable $map): mixed
    {
        if (! is_int($old) && ! (is_string($old) && ctype_digit($old))) {
            return $old;
        }

        $new = $map((int) $old);

        return $new === (int) $old ? $old : $new;
    }

    private static function mapToken(mixed $old, callable $map): mixed
    {
        if (! is_string($old) || ! preg_match('/^E(\d+)$/D', $old, $match)) {
            return $old;
        }

        $new = $map((int) $match[1]);

        return $new === (int) $match[1] ? $old : Expert::tokenFor($new);
    }
}
