<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\OpenPairRegistry;
use Closure;

/**
 * Pick the winning candidate. With a single THINK output it's a pass-through;
 * otherwise the moderator qualitatively judges the contribution intents. The
 * winner is always resolved by id against the project's contributor map — never
 * a global name lookup.
 *
 * Catch-up experts (added to the THINK batch only to refresh a stale memory) are
 * filtered out first: they were never candidates, so letting them win would
 * quietly override the moderator's routing.
 */
class RunOrchestratorSelect
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $map = $ctx->project->contributorMap();
        $eligible = $this->eligibleOutputs($ctx);
        $ids = array_keys($eligible);

        if (count($ids) === 1) {
            $ctx->winner = $map[$ids[0]];

            return $this->withOpenPair($ctx, $next);
        }

        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        $winnerId = $moderator->selectWinner(
            $eligible,
            allowBackToBack: $ctx->mentionShortcut,
            openFloor: $ctx->moderationContext['open_floor_expert'] ?? null,
        );
        $ctx->winner = $map[$winnerId] ?? $map[$ids[0]];

        return $this->withOpenPair($ctx, $next);
    }

    /**
     * Resolve the pair the winner owes an answer to, before SPEAK runs. Whoever
     * ends up speaking may be closing a pair — including a candidate the funnel
     * picked for other reasons — so this is checked for the actual winner rather
     * than assumed from the floor signal.
     */
    protected function withOpenPair(TurnContext $ctx, Closure $next)
    {
        $ctx->openPair = $ctx->winner === null
            ? null
            : app(OpenPairRegistry::class, ['project' => $ctx->project])->pairFor($ctx->winner);

        return $next($ctx);
    }

    /**
     * THINK outputs eligible to win. Falls back to the full set if filtering
     * would leave nothing — a turn must always produce a speaker.
     *
     * @return array<int, array{memory: string, beitragsabsicht: string, topic_done: bool}>
     */
    protected function eligibleOutputs(TurnContext $ctx): array
    {
        $catchUpIds = $ctx->catchUpExperts->pluck('id')->all();

        if (empty($catchUpIds)) {
            return $ctx->thinkOutputs;
        }

        $eligible = array_diff_key($ctx->thinkOutputs, array_flip($catchUpIds));

        return empty($eligible) ? $ctx->thinkOutputs : $eligible;
    }
}
