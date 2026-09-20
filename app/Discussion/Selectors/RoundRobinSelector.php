<?php

namespace App\Discussion\Selectors;

use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/** Fixed rotation by seat: the zero point for turn share. */
class RoundRobinSelector implements SpeakerSelector
{
    public function select(TurnPayload $payload): Selection
    {
        $experts = $payload->project->contributingExperts()->values();

        $lastSpeakerId = $payload->project->messages()
            ->whereNotNull('expert_id')
            ->latest('id')
            ->value('expert_id');

        $lastIndex = $experts->search(fn (Expert $expert) => $expert->id === $lastSpeakerId);
        $nextIndex = $lastIndex === false ? 0 : ($lastIndex + 1) % $experts->count();
        $speaker = $experts[$nextIndex];

        return new Selection($speaker, class_basename(static::class), ['seat' => $speaker->pivot->seat]);
    }
}
