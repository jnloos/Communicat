<?php

namespace App\Services\Transfer;

use App\Discussion\Values\AttributeVisibility;

/**
 * Checks a project file's fields have the types ProjectWriter reads, before
 * anything decides on or writes the project. A wrong type then fails the
 * project with "could not be read" instead of an unexpected error from deep
 * inside the write. Fields whose content is free (run_config, selection,
 * payload, config) are only checked to be objects where the import reads into
 * them.
 */
final class ProjectFileShape
{
    private const TEXTS = ['uuid', 'title', 'description', 'pipeline', 'model', 'long_term_memory', 'attribute_visibility'];

    private const NUMBERS = ['seed', 'turn_budget', 'summarize_threshold', 'summarize_oldest', 'summarized_until_message'];

    private const LISTS = ['run_config', 'tags', 'expert_ids', 'seats', 'messages', 'summaries', 'jobs'];

    private const JOB_TEXTS = ['job_class', 'status', 'error', 'expert'];

    private const JOB_NUMBERS = ['turn_index', 'seat', 'words', 'chars', 'thought_words', 'thought_chars', 'reasoning_tokens'];

    private const PROMPT_TEXTS = ['label', 'model', 'prompt', 'response', 'provider', 'checkpoint', 'model_served', 'purpose', 'expert', 'reasoning', 'status', 'error'];

    private const PROMPT_NUMBERS = ['latency_ms', 'tokens_in', 'tokens_out', 'tokens_reasoning', 'tokens_cached'];

    /** @throws UnreadableEntry */
    public static function check(mixed $data): void
    {
        $data = EntryFields::entry($data);
        self::fields($data, self::TEXTS, self::NUMBERS, ['created_at']);

        foreach (self::LISTS as $key) {
            EntryFields::map($data, $key);
        }

        EntryFields::texts($data, 'expert_ids');

        self::visibility($data);

        foreach (EntryFields::map($data, 'seats') as $seat) {
            $seat = EntryFields::entry($seat);
            EntryFields::required($seat, 'expert');
            self::fields($seat, ['expert'], ['seat']);
        }

        foreach (EntryFields::map($data, 'messages') as $message) {
            $message = EntryFields::entry($message);
            EntryFields::required($message, 'key');
            self::fields($message, ['content', 'expert', 'addressee'], ['key', 'job'], ['created_at']);
        }

        foreach (EntryFields::map($data, 'summaries') as $summary) {
            $summary = EntryFields::entry($summary);
            EntryFields::required($summary, 'expert');
            self::fields($summary, ['expert', 'content'], [], ['updated_at']);
        }

        foreach (EntryFields::map($data, 'jobs') as $job) {
            self::job(EntryFields::entry($job));
        }
    }

    private static function visibility(array $data): void
    {
        $visibility = EntryFields::text($data, 'attribute_visibility');

        if ($visibility !== null && AttributeVisibility::tryFrom($visibility) === null) {
            throw new UnreadableEntry('The field attribute_visibility is not a known visibility.');
        }
    }

    private static function job(array $job): void
    {
        EntryFields::required($job, 'key');
        self::fields($job, self::JOB_TEXTS, ['key', ...self::JOB_NUMBERS], ['started_at', 'finished_at']);
        EntryFields::map($job, 'selection');

        foreach (EntryFields::map($job, 'prompts') as $prompt) {
            self::fields(EntryFields::entry($prompt), self::PROMPT_TEXTS, self::PROMPT_NUMBERS, ['created_at']);
        }
    }

    /**
     * @param  list<string>  $texts
     * @param  list<string>  $numbers
     * @param  list<string>  $times
     */
    private static function fields(array $entry, array $texts, array $numbers, array $times = []): void
    {
        foreach ($texts as $key) {
            EntryFields::assertText($entry, $key);
        }

        foreach ($numbers as $key) {
            EntryFields::integer($entry, $key);
        }

        foreach ($times as $key) {
            EntryFields::time($entry, $key);
        }
    }
}
