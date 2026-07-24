<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Models\Expert;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\UserQuestionMemory;
use Closure;

/**
 * When a new user message arrives, make its question known to EVERY contributing
 * expert — not just the candidates the funnel later picks to THINK. The question
 * is written deterministically (no LLM) into each expert's Gedächtnis as a
 * `[AKTUELLE_NUTZERFRAGE]` block and mirrored into project.settings so the
 * prompts can render it prominently. A watermark ensures this runs once per
 * user message.
 */
class SeedUserQuestion
{
    /** Max characters of the user message kept as the focused question. */
    protected const EXCERPT_LENGTH = 240;

    public function handle(TurnContext $ctx, Closure $next)
    {
        $latest = $ctx->project->latestParticipantMessage();

        // Only act on a fresh user message we haven't seeded yet.
        if ($latest === null || $latest->user_id === null) {
            return $next($ctx);
        }

        $settings = $ctx->project->settings ?? [];
        $seededId = (int) ($settings['user_question_seeded_id'] ?? 0);

        if ($latest->id <= $seededId) {
            return $next($ctx);
        }

        $question = mb_substr(trim($latest->content), 0, self::EXCERPT_LENGTH);

        foreach ($ctx->project->contributingExperts() as $expert) {
            /** @var Expert $expert */
            $summary = $expert->thoughtsAbout($ctx->project);
            $summary->content = UserQuestionMemory::upsert($summary->content ?? '', $question);
            $summary->save();
        }

        $settings['current_user_question'] = $question;
        $settings['user_question_seeded_id'] = $latest->id;
        $ctx->project->settings = $settings;
        $ctx->project->save();

        return $next($ctx);
    }
}
