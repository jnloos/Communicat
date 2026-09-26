<?php

namespace App\Discussion\Agents;

use App\Discussion\Values\Purpose;

class SpeakAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Speak;
    }
}
