<?php

namespace App\Pipeline\Stages;

use App\Pipeline\TurnContext;
use App\Services\ModeratorService;
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
        );

        return $next($ctx);
    }
}
