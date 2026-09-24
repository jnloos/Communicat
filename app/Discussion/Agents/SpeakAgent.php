<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

class SpeakAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Speak;
    }
}
