<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

class ThinkAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Think;
    }
}
