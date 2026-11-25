<?php

namespace App\Services\Study\Planning;

/**
 * Thesis study, see docs/study.md.
 *
 * A wave as asked for (manifest, number, range) with its plan and
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
        public readonly array $plans,
        public readonly array $problems,
    ) {}

    public function isAcceptable(): bool
    {
        return $this->problems === [];
    }

    /** What a preview showed, in one hash: the page applies only a wave that still has it. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->manifest?->canonical(), $this->number, $this->from, $this->to]));
    }
}
