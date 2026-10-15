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
 *
 * It wraps the stored array rather than mapping it onto fields, because old
 * runs and imported bundles may carry keys or malformed values no current
 * selector writes, and toArray() must hand every one of them back unchanged.
 */
final readonly class SelectionRecord
{
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

    public static function seatSignals(int $seat): array
    {
        return ['seat' => $seat];
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

        if (is_array($signals['bids'] ?? null)) {
            $bids = [];

            foreach ($signals['bids'] as $bidder => $bid) {
                $bids[$token((string) $bidder)] = $bid;
            }

            $signals['bids'] = $bids;
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
