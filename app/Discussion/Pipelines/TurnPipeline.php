<?php

namespace App\Discussion\Pipelines;

/**
 * One way to run a turn. A new pipeline is a new class in this directory; the
 * registry finds it, the project form lists it, the smoke test covers it.
 */
interface TurnPipeline
{
    /**
     * Ordered stages: class names, or Laravel pipe strings "Class:parameter"
     * (e.g. SelectSpeaker::with(RoundRobinSelector::class)).
     *
     * @return array<int, string>
     */
    public function stages(): array;
}
