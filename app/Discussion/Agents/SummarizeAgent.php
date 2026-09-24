<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

class SummarizeAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Summarize;
    }
}
