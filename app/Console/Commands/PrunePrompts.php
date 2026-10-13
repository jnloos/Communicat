<?php

namespace App\Console\Commands;

use App\Models\PromptLog;
use Illuminate\Console\Command;

/** Replaces prompt texts older than the retention window with a note; runs hourly. */
class PrunePrompts extends Command
{
    protected $signature = 'prompts:prune';

    protected $description = 'Remove prompt texts older than PROMPT_RETENTION_HOURS, keeping responses and tokens.';

    private const MARKER_PREFIX = '[prompt removed after ';

    public static function marker(int $hours): string
    {
        return self::MARKER_PREFIX."{$hours} h retention]";
    }

    public function handle(): int
    {
        $hours = (int) config('discussion.prompt_retention_hours');

        if ($hours <= 0) {
            $this->info('Prompt retention is off, nothing removed.');

            return self::SUCCESS;
        }

        $removed = PromptLog::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->where('prompt', 'not like', self::MARKER_PREFIX.'%')
            ->update(['prompt' => self::marker($hours)]);

        $this->info("{$removed} prompt texts removed.");

        return self::SUCCESS;
    }
}
