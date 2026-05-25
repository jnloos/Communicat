<?php

namespace App\Pipeline\Stages;

use App\Models\Expert;
use App\Models\Message;
use App\Pipeline\Candidates\AllExpertsStrategy;
use App\Pipeline\Candidates\CandidateStrategy;
use App\Pipeline\Candidates\FunnelStrategy;
use App\Pipeline\Directive;
use App\Pipeline\TurnContext;
use App\Services\ModeratorService;
use Closure;
use Illuminate\Support\Collection;

/**
 * Build the candidate pool. A deterministic @-mention in the latest user message
 * wins outright and skips the routing LLM (a Directive is still synthesized).
 * Otherwise the configured CandidateStrategy decides, and that strategy also
 * sets ctx->directive.
 */
class SelectCandidates
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $pendingUser = $this->latestUserMessage($ctx);
        $mentions    = $this->extractUserMentions($pendingUser, $ctx->project->contributingExperts());

        if ($mentions->isNotEmpty()) {
            // Deterministic override: the named experts are the pool; the
            // moderator still picks the best among them in SelectWinner.
            $ctx->candidates = $mentions->values()->all();
            $ctx->directive  = new Directive(
                role:              '',
                agendaStep:        $ctx->moderationContext['agenda_phase'] ?? ModeratorService::AGENDA_PHASES[0],
                convergenceIntent: '',
                addressUser:       false,
                reasoning:         'Direkte @-Ansprache durch Nutzer.',
            );
        } else {
            $ctx->candidates = $this->strategy($ctx)->select($ctx);
        }

        return $next($ctx);
    }

    protected function strategy(TurnContext $ctx): CandidateStrategy
    {
        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        return match (config('discussion.candidate_strategy', 'funnel')) {
            'all'   => new AllExpertsStrategy($moderator),
            default => new FunnelStrategy($moderator),
        };
    }

    protected function latestUserMessage(TurnContext $ctx): ?Message
    {
        $latest = $ctx->latestMessage;

        return ($latest && $latest->user_id !== null) ? $latest : null;
    }

    /**
     * Extract ALL explicit "@PersonaName" mentions from the latest user message
     * and resolve them against contributing experts. Returns a deduplicated,
     * order-preserving collection. Multi-word names match greedily up to three
     * tokens; the longest contributor name wins per mention.
     *
     * @return Collection<int, Expert>
     */
    public function extractUserMentions(?Message $userMsg, Collection $contributors): Collection
    {
        if ($userMsg === null || $userMsg->user_id === null || empty($userMsg->content) || $contributors->isEmpty()) {
            return collect();
        }

        // Anchored to start-of-string or whitespace so '@' inside email-like
        // text ("name@example.com") is ignored.
        $pattern = '/(?:^|\s)@([\p{L}\p{M}\p{Nd}_\-]+(?:[ ][\p{L}\p{M}\p{Nd}_\-]+){0,2})/u';
        if (!preg_match_all($pattern, $userMsg->content, $matches)) {
            return collect();
        }

        $resolved = collect();
        foreach ($matches[1] as $candidate) {
            $candidateLower = mb_strtolower(trim($candidate));
            if ($candidateLower === '') {
                continue;
            }

            $best = null;
            foreach ($contributors as $expert) {
                $nameLower = mb_strtolower($expert->name);
                if ($candidateLower === $nameLower || str_starts_with($candidateLower, $nameLower . ' ')) {
                    if ($best === null || mb_strlen($expert->name) > mb_strlen($best->name)) {
                        $best = $expert;
                    }
                }
            }

            if ($best !== null && !$resolved->contains(fn(Expert $e) => $e->id === $best->id)) {
                $resolved->push($best);
            }
        }

        return $resolved;
    }
}
