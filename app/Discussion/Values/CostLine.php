<?php

namespace App\Discussion\Values;

/**
 * What one model cost inside one run.
 *
 * A run is homogeneous by design, so there is normally one line. There are two
 * when a project's model was changed mid-run -- which `run_config.changes`
 * permits and records -- and splitting by model is then the only way the figure
 * stays true.
 */
final readonly class CostLine
{
    /**
     * @param  string  $model  the literal model id as prompt_logs recorded it,
     *                         not our registry key
     * @param  int  $tokensOut  reasoning tokens included: the providers bill them at
     *                          the output rate and report them inside the output count
     * @param  float|null  $usd  null when no price is configured for this model, so an
     *                           unpriced run reports nothing rather than a wrong zero
     */
    public function __construct(
        public string $provider,
        public string $model,
        public int $calls,
        public int $tokensIn,
        public int $tokensOut,
        public ?float $usd,
    ) {}
}
