<?php

namespace App\Pipeline\Stages;

use App\Pipeline\TurnContext;
use App\Services\AgentService;
use Closure;

/**
 * The winner executes the moderator's Directive in persona, producing ONLY the
 * visible turn text. Next speaker and adjacency-pair type are decided by the
 * moderator/detection downstream (PersistMessage), not by the speaking agent.
 */
class Speak
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $agent       = app(AgentService::class, ['project' => $ctx->project]);
        $thinkOutput = $ctx->thinkOutputs[$ctx->winner->name];

        $ctx->speakResult = $agent->speak($ctx->winner, $thinkOutput, $ctx->directive);

        return $next($ctx);
    }
}
