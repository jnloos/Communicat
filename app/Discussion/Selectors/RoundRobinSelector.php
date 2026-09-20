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

        // If the last speaker is no longer seated (roster changed since), search()
        // returns false and rotation restarts at seat 1. Not balancing — it never
        // favours a rare speaker — but it is the one place a roster change perturbs
        // the position sequence.
        $lastIndex = $experts->search(fn (Expert $expert) => $expert->id === $lastSpeakerId);
        $nextIndex = $lastIndex === false ? 0 : ($lastIndex + 1) % $experts->count();
        $speaker = $experts[$nextIndex];

        return new Selection($speaker, class_basename(static::class), ['seat' => $speaker->pivot->seat]);
    }
}
