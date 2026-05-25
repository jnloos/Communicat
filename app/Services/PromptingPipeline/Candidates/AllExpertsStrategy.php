<?php

namespace App\Services\PromptingPipeline\Candidates;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;

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
