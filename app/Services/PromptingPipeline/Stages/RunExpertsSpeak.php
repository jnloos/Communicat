<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\AgentService;
use Closure;

/**
 * The winner executes the moderator's Directive in persona, producing ONLY the
 * visible turn text. Next speaker and adjacency-pair type are decided by the
 * moderator/detection downstream (PersistMessage), not by the speaking agent.
 */
class RunExpertsSpeak
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $agent       = app(AgentService::class, ['project' => $ctx->project]);
        $thinkOutput = $ctx->thinkOutputs[$ctx->winner->id];

        $ctx->speakResult = $agent->speak($ctx->winner, $thinkOutput, $ctx->directive);

        return $next($ctx);
    }
}
