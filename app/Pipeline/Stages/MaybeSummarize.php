<?php

namespace App\Pipeline\Stages;

use App\Pipeline\TurnContext;
use App\Services\Summarizer;
use Closure;

/**
 * Final stage: maybe compress old history. The floor outcome was already derived
 * in PersistMessage.
 */
class MaybeSummarize
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        app(Summarizer::class, ['project' => $ctx->project])->maybeRun();

        return $next($ctx);
    }
}
