<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
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

        return $next($ctx);
    }
}
