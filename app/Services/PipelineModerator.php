<?php

namespace App\Services;

use App\Models\Project;
use App\Pipeline\Stages\MaybeSummarize;
use App\Pipeline\Stages\PersistMessage;
use App\Pipeline\Stages\ResolveModerationContext;
use App\Pipeline\Stages\RunThink;
use App\Pipeline\Stages\SelectCandidates;
use App\Pipeline\Stages\SelectWinner;
use App\Pipeline\Stages\Speak;
use App\Pipeline\Stages\UpdateState;
use App\Pipeline\TurnContext;
use Illuminate\Pipeline\Pipeline;

class PipelineModerator
{
    public function __construct(
        protected Project $project,
        protected ?int $jobLogId = null,
    ) {}

    /**
     * Run one moderator-driven funnel turn through the pipeline:
     *   ResolveModerationContext → SelectCandidates → RunThink → SelectWinner
     *   → Speak → PersistMessage → UpdateState → MaybeSummarize
     *
     * @return array{stop: bool, reason: ?string, next_speaker: ?string}
     */
    public function run(): array
    {
        $ctx = app(Pipeline::class)
            ->send(new TurnContext($this->project, $this->jobLogId))
            ->through([
                ResolveModerationContext::class,
                SelectCandidates::class,
                RunThink::class,
                SelectWinner::class,
                Speak::class,
                PersistMessage::class,
                UpdateState::class,
                MaybeSummarize::class,
            ])
            ->thenReturn();

        return [
            'stop'         => $ctx->stop,
            'reason'       => $ctx->reason,
            'next_speaker' => $ctx->nextSpeaker,
        ];
    }
}
