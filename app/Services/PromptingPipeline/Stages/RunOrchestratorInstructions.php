<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Message;
use App\Services\PromptingPipeline\Candidates\AllExpertsStrategy;
use App\Services\PromptingPipeline\Candidates\CandidateStrategy;
use App\Services\PromptingPipeline\Candidates\FunnelStrategy;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\MentionResolver;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\ProgressTracker;
use Closure;

/**
 * First stage: resolve the advisory signals the moderator needs (latest message,
 * trigger note, agenda phase, a pending/unanswered user message) and let the
 * configured CandidateStrategy build the pool and the turn Directive.
 *
 * User priority is no longer hard-coded: the pending user excerpt is exposed to
 * the moderator, which decides addressUser itself. One deterministic exception:
 * when the user @-mentions contributing experts, they become the candidate set
 * directly and the route LLM call is skipped entirely (mention shortcut).
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
        $contributorCount = $ctx->project->contributingExperts()->count();
        $inclusionThreshold = $ctx->project->userInclusionThreshold();

        // Progress/closure signals (stagnation, covered/resolved ledgers, and —
        // when due — the periodic LLM closure verdict). Persists its own state.
        $progress = app(ProgressTracker::class, ['project' => $ctx->project])->signals();

        // Deterministic floor: if the last turn addressed a specific expert with
        // an open pair (question/address), that expert holds the floor next.
        $floorExpert = $this->openFloorExpert($ctx);

        $ctx->moderationContext = [
            'agenda_phase' => $moderator->agendaPhase(),
            'pending_user' => $pendingExcerpt,
            'pending_user_name' => $pendingExcerpt !== null ? $latest->user?->name : null,
            'current_user_question' => $ctx->project->settings['current_user_question'] ?? null,
            'contributor_count' => $contributorCount,
            'expert_turns_since_user' => $ctx->project->expertTurnsSinceLastUserMessage(),
            'inclusion_threshold' => $inclusionThreshold,
            'user_inclusion_due' => $ctx->project->userInclusionDue(),
            'topic_clarification_due' => $ctx->project->topicClarificationDue(),
            'description_sparse' => $ctx->project->descriptionIsSparse(),
            'participant_message_count' => $ctx->project->participantMessages()->count(),
            // Progress / anti-circularity
            'stagnation' => $progress['stagnation'],
            'closure_due' => $progress['closure_due'],
            'point_resolved' => $progress['point_resolved'],
            'going_in_circles' => $progress['going_in_circles'],
            'next_move' => $progress['next_move'],
            'open_question' => $progress['open_question'],
            'zwischenergebnis' => $progress['zwischenergebnis'],
            'covered_points' => $progress['covered_points'],
            'resolved_points' => $progress['resolved_points'],
            // Floor
            'open_floor_expert' => $floorExpert !== null
                ? ['name' => $floorExpert->name, 'prompt_id' => $floorExpert->promptId]
                : null,
        ];

        // Mention shortcut: a user @-mention picks the candidates deterministically
        // and skips the route LLM call (and, with a single mention, select too).
        if (config('discussion.mention_shortcut', true)) {
            $mentioned = app(MentionResolver::class)
                ->match($ctx->latestMessage, $ctx->project->contributingExperts());

            if (! empty($mentioned)) {
                $ctx->directive = $moderator->mentionDirective($ctx->moderationContext);
                $ctx->candidates = $mentioned;
                $ctx->mentionShortcut = true;

                return $next($ctx);
            }
        }

        $ctx->candidates = $this->strategy($ctx)->select($ctx);

        // Floor guarantee: the addressed expert of an open pair must be able to
        // answer, so merge them into the candidate set even if the funnel didn't
        // pick them. The back-to-back guard (in selectWinner) still keeps the
        // addresser from immediately going again.
        if ($floorExpert !== null) {
            $alreadyIn = collect($ctx->candidates)->contains(fn (Expert $e) => $e->id === $floorExpert->id);
            if (! $alreadyIn) {
                $ctx->candidates[] = $floorExpert;
            }
        }

        return $next($ctx);
    }

    /**
     * The expert who holds the floor next by adjacency: the addressee of the
     * latest expert turn when it opened a question/address pair. Null otherwise.
     */
    protected function openFloorExpert(TurnContext $ctx): ?Expert
    {
        $latest = $ctx->latestMessage;

        if ($latest === null || $latest->expert_id === null) {
            return null;
        }

        if ($latest->adjacency_partner_type !== Expert::class || $latest->adjacency_partner_id === null) {
            return null;
        }

        if (! in_array($latest->adjacency_pair_type, [Message::PAIR_FRAGE_ANTWORT, Message::PAIR_ANSPRACHE_REAKTION], true)) {
            return null;
        }

        return $ctx->project->contributorMap()->get($latest->adjacency_partner_id);
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
