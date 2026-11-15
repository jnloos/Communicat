<?php

namespace App\Study\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/** The breaker paused the study. Fired once per pause; the alerting (sub-project D) listens. */
final class BreakerTripped
{
    use Dispatchable;

    public function __construct(
        public readonly string $signal,
        public readonly string $reason,
        public readonly ?int $projectId,
        public readonly CarbonImmutable $at,
    ) {}
}
