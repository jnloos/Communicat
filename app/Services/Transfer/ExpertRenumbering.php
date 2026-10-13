<?php

namespace App\Services\Transfer;

use App\Models\Expert;

/**
 * Where an imported run's experts got different ids than in the source
 * database, this carries the ids stored inside its JSON columns along:
 * a turn's selection (speaker_id, the keys of signals.bids, the tokens in
 * signals.fallbacks and signals.token, the values of signals.order) and the
 * cast frozen in run_config. An id the map does not know stays as it is.
 *
 * Every result is built fresh rather than edited in place, so a chain like
 * 1 → 2, 2 → 3 cannot overwrite itself. Texts are never rewritten: E-tokens
 * inside prompts, answers and reasoning keep naming the source ids.
 */
final class ExpertRenumbering
{
    /** @param  array<int, int>  $renumbered  original id => id here, only for ids that changed */
    public function __construct(private readonly array $renumbered) {}

    /**
     * The map from a file's expert_ids (original id => uuid) to the ids those
     * uuids hold here.
     *
     * @param  array<int|string, mixed>  $expertIds
     */
    public static function fromExpertIds(array $expertIds): self
    {
        $original = collect($expertIds)->filter(fn ($uuid) => is_string($uuid));
        $here = Expert::whereIn('uuid', $original->values())->pluck('id', 'uuid');
        $renumbered = [];

        foreach ($original as $oldId => $uuid) {
            $newId = isset($here[$uuid]) ? (int) $here[$uuid] : null;

            if ($newId !== null && $newId !== (int) $oldId) {
                $renumbered[(int) $oldId] = $newId;
            }
        }

        return new self($renumbered);
    }

    public function isEmpty(): bool
    {
        return $this->renumbered === [];
    }

    public function selection(?array $selection): ?array
    {
        if ($selection === null || $this->isEmpty()) {
            return $selection;
        }

        if (array_key_exists('speaker_id', $selection)) {
            $selection['speaker_id'] = $this->id($selection['speaker_id']);
        }

        if (is_array($selection['signals'] ?? null)) {
            $selection['signals'] = $this->signals($selection['signals']);
        }

        return $selection;
    }

    /** run_config with the expert_id of every cast entry renumbered; everything else untouched. */
    public function runConfig(?array $runConfig): ?array
    {
        if ($runConfig === null || $this->isEmpty() || ! is_array($runConfig['cast'] ?? null)) {
            return $runConfig;
        }

        $runConfig['cast'] = array_map(function ($member) {
            if (is_array($member) && array_key_exists('expert_id', $member)) {
                $member['expert_id'] = $this->id($member['expert_id']);
            }

            return $member;
        }, $runConfig['cast']);

        return $runConfig;
    }

    private function signals(array $signals): array
    {
        if (is_array($signals['bids'] ?? null)) {
            $bids = [];

            foreach ($signals['bids'] as $bidder => $bid) {
                $bids[$this->token((string) $bidder)] = $bid;
            }

            $signals['bids'] = $bids;
        }

        if (is_array($signals['fallbacks'] ?? null)) {
            $signals['fallbacks'] = array_map($this->token(...), $signals['fallbacks']);
        }

        if (array_key_exists('token', $signals)) {
            $signals['token'] = $this->token($signals['token']);
        }

        if (is_array($signals['order'] ?? null)) {
            $signals['order'] = array_map($this->id(...), $signals['order']);
        }

        return $signals;
    }

    private function id(mixed $old): mixed
    {
        return is_int($old) || (is_string($old) && ctype_digit($old)) ? ($this->renumbered[(int) $old] ?? $old) : $old;
    }

    private function token(mixed $old): mixed
    {
        return is_string($old) && preg_match('/^E(\d+)$/D', $old, $match) && isset($this->renumbered[(int) $match[1]])
            ? Expert::tokenFor($this->renumbered[(int) $match[1]])
            : $old;
    }
}
