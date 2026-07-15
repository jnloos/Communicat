<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Events\PipelineStageChanged;
use App\Services\PromptingPipeline\Candidates\AllExpertsStrategy;
use App\Services\PromptingPipeline\Candidates\CandidateStrategy;
use App\Services\PromptingPipeline\Candidates\FunnelStrategy;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
use Closure;

/**
 * First stage: resolve the advisory signals the moderator needs (latest message,
 * trigger note, agenda phase, a pending/unanswered user message) and let the
 * configured CandidateStrategy build the pool and the turn Directive.
 *
 * User priority is no longer hard-coded: the pending user excerpt is exposed to
 * the moderator, which decides addressUser itself. There is no @-mention special
 * case either — the moderator infers direct address from the visible transcript.
 */
class RunOrchestratorInstructions
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        PipelineStageChanged::dispatch($ctx->project->id, 'routing');

        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        $ctx->latestMessage = $ctx->project->latestParticipantMessage();

        $latest = $ctx->latestMessage;
        $pendingExcerpt = ($latest && $latest->user_id !== null)
            ? mb_substr(trim($latest->content), 0, 240)
            : null;

        $ctx->moderationNote = $moderator->checkTriggers();
        $ctx->moderationContext = [
            'agenda_phase' => $moderator->agendaPhase(),
            'pending_user' => $pendingExcerpt,
        ];

        $ctx->candidates = $this->strategy($ctx)->select($ctx);

        return $next($ctx);
    }

    protected function strategy(TurnContext $ctx): CandidateStrategy
    {
        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        return match (config('discussion.candidate_strategy', 'funnel')) {
            'all' => new AllExpertsStrategy($moderator),
            default => new FunnelStrategy($moderator),
        };
    }
}
