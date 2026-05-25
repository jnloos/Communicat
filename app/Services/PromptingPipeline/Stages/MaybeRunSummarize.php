<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\Summarizer;
use Closure;

/**
 * Final stage: maybe compress old history. The floor outcome was already derived
 * in PersistMessage.
 */
class MaybeRunSummarize
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        app(Summarizer::class, ['project' => $ctx->project])->maybeRun();

        return $next($ctx);
    }
}
