<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\ProgressTracker;
use Closure;

/**
 * Roll forward moderator state after the turn: recent speakers/response types,
 * silence counters, openers, and the agenda phase.
 */
class UpdateState
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        $moderator->updateState(
            $ctx->winner,
            $ctx->message->adjacency_pair_type ?? '',
            $ctx->speakResult['content'] ?? '',
            $ctx->thinkOutputs[$ctx->winner->id]['beitragsabsicht'] ?? '',
        );

        // Record every thinking expert's own verdict on whether the current topic
        // is exhausted (THINK's THEMA_STATUS). Consumed at the next turn's start by
        // ProgressTracker::signals() as the persona-driven topic-done signal.
        $votes = [];
        foreach ($ctx->thinkOutputs as $expertId => $output) {
            if (array_key_exists('topic_done', $output)) {
                $votes[$expertId] = (bool) $output['topic_done'];
            }
        }
        app(ProgressTracker::class, ['project' => $ctx->project])->recordTopicVotes($votes);

        $this->rollHandoffCooldown($ctx);

        return $next($ctx);
    }

    /**
     * Arm the hand-off cooldown when this turn handed the floor to a human,
     * otherwise burn one expert turn off it. Read back by ModeratorService's
     * handoff clamp so two hand-offs cannot follow each other directly.
     *
     * Runs after ModeratorService::updateState(), which already persisted the
     * project — so the settings read here are current.
     */
    protected function rollHandoffCooldown(TurnContext $ctx): void
    {
        $settings = $ctx->project->settings ?? [];

        $settings['handoff_cooldown_left'] = $ctx->message?->handsBackToUser()
            ? max(0, (int) config('discussion.handoff_cooldown_turns', 2))
            : max(0, (int) ($settings['handoff_cooldown_left'] ?? 0) - 1);

        // Reaction cadence: reset on a reaction turn, otherwise count up. Read
        // back by ModeratorService::applyReactionCadence so short reactions are
        // spaced out instead of firing on every long-turn streak.
        $settings['turns_since_reaction'] = $ctx->directive?->role === 'kurz_reagieren'
            ? 0
            : (int) ($settings['turns_since_reaction'] ?? 0) + 1;

        $ctx->project->settings = $settings;
        $ctx->project->save();
    }
}
