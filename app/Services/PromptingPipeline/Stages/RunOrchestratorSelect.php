<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
use Closure;

/**
 * Pick the winning candidate. With a single THINK output it's a pass-through;
 * otherwise the moderator qualitatively judges the contribution intents. The
 * winner is always resolved by id against the project's contributor map — never
 * a global name lookup.
 */
class RunOrchestratorSelect
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $ids = array_keys($ctx->thinkOutputs);
        $map = $ctx->project->contributorMap();

        if (count($ids) === 1) {
            $ctx->winner = $map[$ids[0]];

            return $next($ctx);
        }

        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        $winnerId = $moderator->selectWinner($ctx->thinkOutputs);
        $ctx->winner = $map[$winnerId] ?? $map[$ids[0]];

        return $next($ctx);
    }
}
