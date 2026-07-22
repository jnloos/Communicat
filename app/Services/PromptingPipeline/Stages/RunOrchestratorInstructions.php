<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Events\PipelineStageChanged;
use App\Services\PromptingPipeline\Candidates\AllExpertsStrategy;
use App\Services\PromptingPipeline\Candidates\CandidateStrategy;
use App\Services\PromptingPipeline\Candidates\FunnelStrategy;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\MentionResolver;
use App\Services\PromptingPipeline\Support\ModeratorService;
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

        $ctx->moderationContext = [
            'agenda_phase' => $moderator->agendaPhase(),
            'pending_user' => $pendingExcerpt,
            'pending_user_name' => $pendingExcerpt !== null ? $latest->user?->name : null,
            'contributor_count' => $contributorCount,
            'expert_turns_since_user' => $ctx->project->expertTurnsSinceLastUserMessage(),
            'inclusion_threshold' => $inclusionThreshold,
            'user_inclusion_due' => $ctx->project->userInclusionDue(),
            'topic_clarification_due' => $ctx->project->topicClarificationDue(),
            'description_sparse' => $ctx->project->descriptionIsSparse(),
            // #region agent log
            'participant_message_count' => (function () use ($ctx) {
                $ref = new \ReflectionMethod($ctx->project, 'participantMessages');
                file_put_contents(base_path('.cursor/debug-c0b61f.log'), json_encode([
                    'sessionId' => 'c0b61f',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'A',
                    'location' => 'RunOrchestratorInstructions.php:participant_message_count',
                    'message' => 'About to call participantMessages from outside Project',
                    'data' => [
                        'method_exists' => method_exists($ctx->project, 'participantMessages'),
                        'is_public' => $ref->isPublic(),
                        'is_private' => $ref->isPrivate(),
                        'project_id' => $ctx->project->id,
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                ])."\n", FILE_APPEND);

                try {
                    $count = $ctx->project->participantMessages()->count();
                    file_put_contents(base_path('.cursor/debug-c0b61f.log'), json_encode([
                        'sessionId' => 'c0b61f',
                        'runId' => 'post-fix',
                        'hypothesisId' => 'A',
                        'location' => 'RunOrchestratorInstructions.php:after_call',
                        'message' => 'participantMessages call succeeded',
                        'data' => ['count' => $count],
                        'timestamp' => (int) (microtime(true) * 1000),
                    ])."\n", FILE_APPEND);

                    return $count;
                } catch (\Throwable $e) {
                    file_put_contents(base_path('.cursor/debug-c0b61f.log'), json_encode([
                        'sessionId' => 'c0b61f',
                        'runId' => 'post-fix',
                        'hypothesisId' => 'A',
                        'location' => 'RunOrchestratorInstructions.php:catch',
                        'message' => 'participantMessages call failed',
                        'data' => [
                            'error' => $e->getMessage(),
                            'exception_class' => $e::class,
                        ],
                        'timestamp' => (int) (microtime(true) * 1000),
                    ])."\n", FILE_APPEND);
                    throw $e;
                }
            })(),
            // #endregion
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
