<?php

namespace App\Pipeline\Candidates;

use App\Pipeline\TurnContext;
use App\Services\ModeratorService;

/**
 * Alternative strategy: every contributing expert is a candidate. The moderator
 * is still consulted (via route) purely to obtain the turn Directive — its
 * candidate narrowing is discarded.
 */
class AllExpertsStrategy implements CandidateStrategy
{
    public function __construct(protected ModeratorService $moderator) {}

    public function select(TurnContext $ctx): array
    {
        $route = $this->moderator->route($ctx->moderationNote, $ctx->moderationContext);
        $ctx->directive = $route['directive'];

        return $ctx->project->contributingExperts()->all();
    }
}
