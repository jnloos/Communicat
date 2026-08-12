<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Events\PipelineStageChanged;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\AgentService;
use App\Services\PromptingPipeline\Support\SpeakTrailerResolver;
use Closure;

/**
 * The winner executes the moderator's Directive in persona, producing the
 * visible turn text plus a STEUERUNG trailer naming who it addresses. The
 * trailer is then reconciled against the prose by SpeakTrailerResolver, so a
 * missing or self-contradicting trailer no longer loses the addressee.
 */
class RunExpertsSpeak
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        PipelineStageChanged::dispatch($ctx->project->id, 'speaking', [[
            'id' => $ctx->winner->id,
            'name' => $ctx->winner->name,
            'avatar_url' => $ctx->winner->avatar_url,
        ]]);

        $agent = app(AgentService::class, ['project' => $ctx->project]);
        $thinkOutput = $ctx->thinkOutputs[$ctx->winner->id];

        $ctx->speakResult = app(SpeakTrailerResolver::class)->resolve(
            $agent->speak($ctx->winner, $thinkOutput, $ctx->directive, $ctx->openPair),
            $ctx->project,
            $ctx->winner,
            $ctx->directive,
        );

        return $next($ctx);
    }
}
