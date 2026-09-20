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

        $payload->select($selector->select($payload));

        return $next($payload);
    }
}
