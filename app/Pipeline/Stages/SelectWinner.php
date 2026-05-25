<?php

namespace App\Pipeline\Stages;

use App\Models\Expert;
use App\Pipeline\TurnContext;
use App\Services\ModeratorService;
use Closure;

/**
 * Pick the winning candidate. With a single THINK output it's a pass-through;
 * otherwise the moderator qualitatively judges the contribution intents.
 */
class SelectWinner
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $names = array_keys($ctx->thinkOutputs);

        if (count($names) === 1) {
            $ctx->winner = Expert::findByName($names[0]);
            return $next($ctx);
        }

        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);
        $openPair  = $ctx->moderationContext['open_adjacency_pair'] ?? null;

        $winnerName  = $moderator->selectWinner($ctx->thinkOutputs, $openPair);
        $ctx->winner = Expert::findByName($winnerName);

        return $next($ctx);
    }
}
