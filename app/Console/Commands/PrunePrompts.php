<?php

namespace App\Console\Commands;

use App\Models\PromptLog;
use Illuminate\Console\Command;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Replaces prompt texts older than the retention window with a note, and
 * deletes uploads left behind for as long (an uploaded bundle holds prompt
 * texts too, and Livewire only clears old uploads on the next upload). Hourly.
 */
class PrunePrompts extends Command
{
    protected $signature = 'prompts:prune';

    protected $description = 'Remove prompt texts older than PROMPT_RETENTION_HOURS, keeping responses and tokens.';

    private const MARKER_PREFIX = '[prompt removed after ';

    public static function marker(int $hours): string
    {
        return self::MARKER_PREFIX."{$hours} h retention]";
    }

    /** Whether a stored prompt is the note this command left in place of the text. */
    public static function removed(string $prompt): bool
    {
        return str_starts_with($prompt, self::MARKER_PREFIX);
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
        $this->info("{$this->pruneUploads($hours)} left-behind uploads deleted.");

        return self::SUCCESS;
    }

    private function pruneUploads(int $hours): int
    {
        if (FileUploadConfiguration::isUsingS3()) {
            return 0;
        }

        $storage = FileUploadConfiguration::storage();
        $cutoff = now()->subHours($hours)->timestamp;
        $deleted = 0;

        foreach ($storage->allFiles(FileUploadConfiguration::path()) as $file) {
            // Another request may have cleared it in between.
            if ($storage->exists($file) && $storage->lastModified($file) < $cutoff && $storage->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
