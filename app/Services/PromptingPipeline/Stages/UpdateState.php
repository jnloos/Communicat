<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\ProgressTracker;
use Closure;

/**
 * Roll forward moderator state after the turn: recent speakers/response types,
 * silence counters, openers, and the agenda phase.
 */
class UpdateState
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        $moderator->updateState(
            $ctx->winner,
            $ctx->message->adjacency_pair_type ?? '',
            $ctx->speakResult['content'] ?? '',
            $ctx->thinkOutputs[$ctx->winner->id]['beitragsabsicht'] ?? '',
        );

        // Record every thinking expert's own verdict on whether the current topic
        // is exhausted (THINK's THEMA_STATUS). Consumed at the next turn's start by
        // ProgressTracker::signals() as the persona-driven topic-done signal.
        $votes = [];
        foreach ($ctx->thinkOutputs as $expertId => $output) {
            if (array_key_exists('topic_done', $output)) {
                $votes[$expertId] = (bool) $output['topic_done'];
            }
        }
        app(ProgressTracker::class, ['project' => $ctx->project])->recordTopicVotes($votes);

        return $next($ctx);
    }
}
