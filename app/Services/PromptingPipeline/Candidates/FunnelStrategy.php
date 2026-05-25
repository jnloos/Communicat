<?php

namespace App\Services\PromptingPipeline\Candidates;

use App\Models\Expert;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;

/**
 * Default strategy: the moderator narrows the contributing experts to a subset
 * (the funnel) and produces the turn's Directive in the same LLM call. The
 * resulting Directive is written onto the context.
 */
class FunnelStrategy implements CandidateStrategy
{
    public function __construct(protected ModeratorService $moderator) {}

    public function select(TurnContext $ctx): array
    {
        $route = $this->moderator->route($ctx->moderationNote, $ctx->moderationContext);

        $ctx->directive = $route['directive'];

        /** @var string[] $names */
        $names = $route['candidates'];

        $selected = !empty($names)
            ? Expert::findManyByName($names)
            : $ctx->project->contributingExperts();

        if ($selected->isEmpty()) {
            $selected = $ctx->project->contributingExperts();
        }

        return $selected->all();
    }
}
