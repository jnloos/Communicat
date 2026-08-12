<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Project;

/**
 * "The last few expert turns were all long."
 *
 * Two consumers need this: the SPEAK prompt (which then carries a brevity
 * instruction) and the moderator's reaction cadence (which schedules an actual
 * short reaction turn). Keeping the rule here means they can never drift apart —
 * and, unlike reading it off PromptBuilder, a stage can ask for it without
 * depending on the prompt renderer.
 */
class BrevitySignal
{
    public static function forProject(Project $project): bool
    {
        return self::forMessages($project->asPromptArray()['messages'] ?? []);
    }

    /**
     * @param  array<int, array{prompt_id: ?string, content: string}>  $messages
     */
    public static function forMessages(array $messages): bool
    {
        $streak = max(1, (int) config('discussion.brevity_streak', 3));
        $minChars = max(1, (int) config('discussion.brevity_min_chars', 200));

        $expertTurns = array_values(array_filter(
            $messages,
            fn (array $m) => str_starts_with((string) ($m['prompt_id'] ?? ''), 'E'),
        ));

        $recent = array_slice($expertTurns, -$streak);

        if (count($recent) < $streak) {
            return false;
        }

        foreach ($recent as $message) {
            if (mb_strlen(trim((string) ($message['content'] ?? ''))) < $minChars) {
                return false;
            }
        }

        return true;
    }
}
