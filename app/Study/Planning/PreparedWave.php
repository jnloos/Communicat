<?php

namespace App\Study\Planning;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * A wave as asked for -- manifest, number, range, decision -- with its plan and
 * every reason it would be refused (spec 1.2, 1.3, 3). What the preview shows
 * and what record() accepts only when the list is empty.
 */
final class PreparedWave
{
    /**
     * @param  list<RunPlan>  $plans  empty when the manifest or the range is unusable
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly ?Manifest $manifest,
        public readonly int $number,
        public readonly int $from,
        public readonly int $to,
        public readonly ?string $decision,
        public readonly array $plans,
        public readonly array $problems,
    ) {}

    public function isAcceptable(): bool
    {
        return $this->problems === [];
    }
}
