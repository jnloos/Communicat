<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

/** Picks the next speaker from the visible history. Sees no private thoughts. */
class SelectorAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Select;
    }
}
