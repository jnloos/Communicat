<?php

namespace App\Services\PromptingPipeline;

use App\Models\Project;
use App\Services\PromptingPipeline\Stages\MaybeRunSummarize;
use App\Services\PromptingPipeline\Stages\PersistMessage;
use App\Services\PromptingPipeline\Stages\ResolveContext;
use App\Services\PromptingPipeline\Stages\RunExpertsThink;
use App\Services\PromptingPipeline\Stages\RunModeratorSelect;
use App\Services\PromptingPipeline\Stages\SelectWinner;
use App\Services\PromptingPipeline\Stages\RunExpertsSpeak;
use App\Services\PromptingPipeline\Stages\UpdateState;
use App\Services\PromptingPipeline\Data\TurnContext;
use Illuminate\Pipeline\Pipeline;

class DiscussionPipeline
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
                ResolveContext::class,
                RunModeratorSelect::class,
                RunExpertsThink::class,
                SelectWinner::class,
                RunExpertsSpeak::class,
                PersistMessage::class,
                UpdateState::class,
                MaybeRunSummarize::class,
            ])
            ->thenReturn();

        return [
            'stop'         => $ctx->stop,
            'reason'       => $ctx->reason,
            'next_speaker' => $ctx->nextSpeaker,
        ];
    }
}
