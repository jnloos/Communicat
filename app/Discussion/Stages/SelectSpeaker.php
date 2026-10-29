<?php

namespace App\Discussion\Stages;

use App\Discussion\Selectors\SpeakerSelector;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use Closure;
use InvalidArgumentException;

class SelectSpeaker
{
    /** Pipe string for a pipeline's stage list: SelectSpeaker::with(RoundRobinSelector::class). */
    public static function with(string $selectorClass): string
    {
        return static::class.':'.$selectorClass;
    }

    public function handle(TurnPayload $payload, Closure $next, string $selectorClass)
    {
        PipelineStageChanged::announce($payload->project->id, 'routing');

        $selector = app($selectorClass);

        if (! $selector instanceof SpeakerSelector) {
            throw new InvalidArgumentException("[{$selectorClass}] does not implement SpeakerSelector.");
        }

        $payload->select($selector->select($payload)->withSignals($payload->selectionSignals()));

        // A selector or an earlier stage may end the turn (the Jev outage guard): the choice is
        // recorded, nobody speaks. No base selector halts, so for them this never applies.
        if ($payload->stop) {
            return $payload;
        }

        return $next($payload);
    }
}
