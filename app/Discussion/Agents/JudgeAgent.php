<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

/** Scores the agents' drafts. Its own purpose so judge calls are filterable in prompt_logs. */
class JudgeAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Judge;
    }
}
