<?php

namespace App\Discussion\Selectors;

use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;

/**
 * The study's independent variable: one class per floor-control mechanism.
 * A selector never balances participation and never favours rare speakers.
 */
interface SpeakerSelector
{
    public function select(TurnPayload $payload): Selection;
}
