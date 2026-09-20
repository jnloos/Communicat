<?php

namespace Tests\Fixtures\Pipelines;

use App\Discussion\Pipelines\TurnPipeline;

class DummyPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [];
    }
}
