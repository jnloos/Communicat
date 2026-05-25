<?php

namespace App\Pipeline\Stages;

use App\Models\Expert;
use App\Pipeline\TurnContext;
use App\Services\AgentService;
use App\Services\OpenAIClient;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Run THINK for every candidate. Partial or total failures degrade gracefully.
 */
class RunThink
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $agent      = app(AgentService::class, ['project' => $ctx->project]);
        $candidates = collect($ctx->candidates);

        if ($candidates->count() === 1) {
            $expert = $candidates->first();
            $ctx->thinkOutputs[$expert->name] = $agent->think($expert);

            return $this->guard($ctx, $next);
        }

        $ctx->thinkOutputs = $this->runConcurrent($ctx, $agent, $candidates);

        return $this->guard($ctx, $next);
    }

    /**
     * Build prompts on the main thread (closures inside sendManySlow may only
     * capture primitives), fire them concurrently, then persist memory per
     * expert back here. Experts whose call failed or returned empty are dropped.
     *
     * @param Collection<int, Expert> $candidates
     * @return array<string, array{memory: string, beitragsabsicht: string}>
     */
    protected function runConcurrent(TurnContext $ctx, AgentService $agent, Collection $candidates): array
    {
        $client    = app(OpenAIClient::class);
        $promptMap = $candidates->mapWithKeys(
            fn(Expert $e) => [$e->name => $agent->thinkPrompt($e)]
        )->all();

        try {
            $responses = $client->sendManySlow($promptMap, 'think');
        } catch (\Throwable $e) {
            Log::warning('Concurrent THINK failed entirely; falling back to first candidate', [
                'project_id' => $ctx->project->id,
                'error'      => $e->getMessage(),
            ]);
            $responses = [];
        }

        $expertByName = $candidates->keyBy('name');
        $outputs      = [];
        foreach ($responses as $name => $response) {
            if (!isset($expertByName[$name]) || !is_string($response) || trim($response) === '') {
                continue;
            }
            $outputs[$name] = $agent->consumeThink($expertByName[$name], $response);
        }

        // Total failure fallback: run the first candidate inline so the turn
        // can still proceed.
        if (empty($outputs)) {
            $first = $candidates->first();
            $outputs[$first->name] = $agent->think($first);
        }

        return $outputs;
    }

    protected function guard(TurnContext $ctx, Closure $next)
    {
        if (empty($ctx->thinkOutputs)) {
            $ctx->stop   = true;
            $ctx->reason = 'no_think_output';
            return $ctx;
        }

        return $next($ctx);
    }
}
