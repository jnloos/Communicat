<?php

namespace App\Discussion\Values;

use App\Models\Project;

/**
 * Jev as one run uses it: the pinned model id, the provider that hosts it, the
 * request timeout and what it decides.
 *
 * fromConfig() is the live setting and is what a pipeline declares when a run
 * starts (TurnRunner freezes it into run_config.decision_model). forProject() is
 * what every later request uses: the frozen value of a started project, so that
 * editing config/ai.php in the middle of a series cannot change the decision
 * maker of a cell that is already running, and the live setting only for a
 * project that has none (nothing has run yet).
 */
final readonly class JevDecisionModel
{
    public function __construct(
        public string $id,
        public string $provider,
        public int $timeout,
        public JevDecides $decides,
        /** Only for the bidder: how its urgency becomes the floor. Null for a selector. */
        public ?BidResolution $bidResolution = null,
    ) {}

    public static function fromConfig(JevDecides $decides): self
    {
        return new self(
            id: (string) config('ai.jev.model'),
            provider: (string) config('ai.jev.provider', 'typesafe'),
            timeout: (int) config('ai.jev.timeout', 60),
            decides: $decides,
            bidResolution: $decides === JevDecides::Bidder ? BidResolution::fromConfig() : null,
        );
    }

    public static function forProject(Project $project, JevDecides $decides): self
    {
        $frozen = $project->run_config['decision_model'] ?? null;

        return is_array($frozen) ? (self::fromFrozen($frozen, $decides) ?? self::fromConfig($decides)) : self::fromConfig($decides);
    }

    /**
     * Stored in run_config.decision_model.
     *
     * @return array{provider: string, model: string, decides: string, timeout: int, bid_resolution?: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->id,
            'decides' => $this->decides->value,
            'timeout' => $this->timeout,
            ...($this->bidResolution === null ? [] : ['bid_resolution' => $this->bidResolution->value]),
        ];
    }

    /**
     * Null for a damaged snapshot (no model or provider), so the live setting
     * answers instead; a snapshot from before the timeout or the resolution were
     * recorded keeps what it has and takes only those from the live setting.
     */
    private static function fromFrozen(array $frozen, JevDecides $decides): ?self
    {
        $id = $frozen['model'] ?? null;
        $provider = $frozen['provider'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($provider) || $provider === '') {
            return null;
        }

        $decides = JevDecides::tryFrom((string) ($frozen['decides'] ?? '')) ?? $decides;
        $timeout = $frozen['timeout'] ?? null;
        $resolution = BidResolution::tryFrom((string) ($frozen['bid_resolution'] ?? ''));

        return new self(
            id: $id,
            provider: $provider,
            timeout: is_int($timeout) && $timeout > 0 ? $timeout : (int) config('ai.jev.timeout', 60),
            decides: $decides,
            bidResolution: $decides === JevDecides::Bidder ? ($resolution ?? BidResolution::fromConfig()) : null,
        );
    }
}
