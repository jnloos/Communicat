<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\AgentService;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Run THINK for every candidate. Partial or total failures degrade gracefully.
 */
class RunExpertsThink
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $agent = app(AgentService::class, ['project' => $ctx->project]);
        $candidates = collect($ctx->candidates);

        // No candidates (e.g. a project without contributing experts): stop the
        // turn gracefully instead of dereferencing a null first() downstream.
        if ($candidates->isEmpty()) {
            $ctx->stop = true;
            $ctx->reason = 'no_candidates';

            return $ctx;
        }

        PipelineStageChanged::dispatch(
            $ctx->project->id,
            'thinking',
            $candidates->map(fn (Expert $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'avatar_url' => $e->avatar_url,
            ])->values()->all(),
        );

        $ctx->catchUpExperts = $this->staleExperts($ctx, $candidates);
        $thinking = $candidates->concat($ctx->catchUpExperts);

        if ($thinking->count() === 1) {
            $expert = $thinking->first();
            $ctx->thinkOutputs[$expert->id] = $agent->think($expert);

            return $this->guard($ctx, $next);
        }

        $ctx->thinkOutputs = $this->runConcurrent($ctx, $agent, $thinking);

        return $this->guard($ctx, $next);
    }

    /**
     * Personas whose memory has fallen furthest behind the conversation, added
     * to this turn's THINK batch purely to catch up.
     *
     * Memory is only written while thinking, so an expert the moderator rarely
     * picks stops knowing what the discussion is about — in the logged project
     * two of four experts still held a 35-character memory after 80 messages,
     * and entered the debate blind whenever they were finally chosen.
     *
     * The batch already runs concurrently through sendManySlow, so one extra
     * prompt costs tokens but no additional wall-clock time. These experts are
     * excluded from the winner selection (RunOrchestratorSelect) — the
     * moderator's routing decision stays untouched.
     *
     * @param  Collection<int, Expert>  $candidates
     * @return Collection<int, Expert>
     */
    protected function staleExperts(TurnContext $ctx, Collection $candidates): Collection
    {
        $budget = (int) config('discussion.memory_catchup_per_turn', 1);

        if ($budget < 1) {
            return collect();
        }

        $latestMessageId = (int) $ctx->project->messages()->max('id');
        $minBacklog = (int) config('discussion.memory_catchup_min_backlog', 3);
        $candidateIds = $candidates->pluck('id')->all();

        return $ctx->project->contributingExperts()
            ->reject(fn (Expert $e) => in_array($e->id, $candidateIds, true))
            ->map(fn (Expert $e) => [
                'expert' => $e,
                'backlog' => $latestMessageId - (int) ($e->thoughtsAbout($ctx->project)->last_message_id ?? 0),
            ])
            ->filter(fn (array $row) => $row['backlog'] >= $minBacklog)
            ->sortByDesc('backlog')
            ->take($budget)
            ->map(fn (array $row) => $row['expert'])
            ->values();
    }

    /**
     * Build prompts on the main thread (closures inside sendManySlow may only
     * capture primitives), fire them concurrently, then persist memory per
     * expert back here. Experts whose call failed or returned empty are dropped.
     *
     * @param  Collection<int, Expert>  $candidates
     * @return array<int, array{memory: string, beitragsabsicht: string}>
     */
    protected function runConcurrent(TurnContext $ctx, AgentService $agent, Collection $candidates): array
    {
        $client = app(OpenAIClient::class);
        $promptMap = $candidates->mapWithKeys(
            fn (Expert $e) => [$e->id => $agent->thinkPrompt($e)]
        )->all();

        try {
            $responses = $client->sendManySlow($promptMap, 'think');
        } catch (\Throwable $e) {
            Log::warning('Concurrent THINK failed entirely; falling back to first candidate', [
                'project_id' => $ctx->project->id,
                'error' => $e->getMessage(),
            ]);
            $responses = [];
        }

        $expertById = $candidates->keyBy('id');
        $outputs = [];
        foreach ($responses as $id => $response) {
            if (! isset($expertById[$id]) || ! is_string($response) || trim($response) === '') {
                continue;
            }
            $outputs[$id] = $agent->consumeThink($expertById[$id], $response);
        }

        // Total failure fallback: run the first candidate inline so the turn
        // can still proceed.
        if (empty($outputs)) {
            $first = $candidates->first();
            $outputs[$first->id] = $agent->think($first);
        }

        return $outputs;
    }

    protected function guard(TurnContext $ctx, Closure $next)
    {
        if (empty($ctx->thinkOutputs)) {
            $ctx->stop = true;
            $ctx->reason = 'no_think_output';

            return $ctx;
        }

        return $next($ctx);
    }
}
