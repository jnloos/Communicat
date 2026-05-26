<?php

namespace App\Services\PromptingPipeline;

use App\Models\Project;
use App\Services\PromptingPipeline\Stages\MaybeRunSummarize;
use App\Services\PromptingPipeline\Stages\PersistMessage;
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
     *   RunModeratorSelect → RunThink → SelectWinner → Speak
     *   → PersistMessage → UpdateState → MaybeSummarize
     *
     * @return array{stop: bool, reason: ?string, user_id: ?int}
     */
    public function run(): array
    {
        $ctx = app(Pipeline::class)
            ->send(new TurnContext($this->project, $this->jobLogId))
            ->through([
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
            'stop'    => $ctx->stop,
            'reason'  => $ctx->reason,
            // The concrete user the expert handed off to (if any), so only that
            // user is prompted for input — not everyone in the project.
            'user_id' => $ctx->message?->handsBackToUser()
                ? $ctx->message->adjacency_partner_id
                : null,
        ];
    }
}
