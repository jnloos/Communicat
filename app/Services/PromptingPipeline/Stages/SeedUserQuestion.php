<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Models\Expert;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\UserQuestionMemory;
use Closure;
use Illuminate\Support\Collection;

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

    /** Below this many characters (mentions removed) there is nothing to answer. */
    protected const MIN_QUESTION_LENGTH = 8;

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

        $question = $this->extractQuestion($latest->content, $ctx->project->contributingExperts());

        // A message that carries no substance of its own — typically a bare
        // "@Beate" handing the floor over — must not replace the question the
        // round is still working on. Advance the watermark anyway so we do not
        // re-check it every turn.
        if ($question === null) {
            $settings['user_question_seeded_id'] = $latest->id;
            $ctx->project->settings = $settings;
            $ctx->project->save();

            return $next($ctx);
        }

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

    /**
     * The substance of a user message, or null when it carries none.
     *
     * The raw message used to be stored verbatim, so a bare "@Werner Falk" ended
     * up as the round's "current question" — and every persona then anchored its
     * memory to a mention instead of an actual question. Strip the mentions
     * first and only keep what is left if there is anything to answer.
     */
    protected function extractQuestion(string $content, Collection $experts): ?string
    {
        $cleaned = trim(preg_replace('/\s+/u', ' ', $this->stripMentions($content, $experts)) ?? $content);

        if (mb_strlen($cleaned) < self::MIN_QUESTION_LENGTH) {
            return null;
        }

        return mb_substr($cleaned, 0, self::EXCERPT_LENGTH);
    }

    /**
     * Remove "@Name" hand-overs, matching against the actual roster.
     *
     * Matching real names matters: a generic "@Word (Capitalised Word)*" pattern
     * swallows the start of the sentence — "@Bob Was hältst du davon?" loses the
     * "Was". Longest names first so "@Anna Richter" is consumed before "@Anna".
     *
     * @param  Collection<int, Expert>  $experts
     */
    protected function stripMentions(string $content, Collection $experts): string
    {
        $names = $experts
            ->flatMap(fn (Expert $e) => [$e->name, $this->firstName($e->name)])
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $name) => mb_strlen($name))
            ->values();

        foreach ($names as $name) {
            $content = preg_replace(
                '/(?:^|(?<=\s))@'.preg_quote($name, '/').'(?!\p{L})/iu',
                ' ',
                $content,
            ) ?? $content;
        }

        return $content;
    }

    protected function firstName(string $name): string
    {
        $tokens = preg_split('/\s+/u', trim($name)) ?: [];

        return $tokens[0] ?? $name;
    }
}
