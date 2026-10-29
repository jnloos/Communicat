<?php

namespace App\Console\Commands;

use App\Discussion\Logging\PromptArchiver;
use App\Models\PromptLog;
use Illuminate\Console\Command;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use RuntimeException;

/**
 * Packs prompt texts older than the retention window into one zstd archive per
 * project (readable again through PromptLog::$prompt_text); calls without a
 * project get a note instead. Also
 * deletes uploads left behind for as long (an uploaded bundle holds prompt
 * texts too, and Livewire only clears old uploads on the next upload). Hourly.
 */
class PrunePrompts extends Command
{
    protected $signature = 'prompts:prune';

    protected $description = 'Archive prompt texts older than PROMPT_RETENTION_HOURS with zstd (calls without a project get a note), keeping responses and tokens.';

    public const MARKER_PREFIX = '[prompt removed after ';

    public static function marker(int $hours): string
    {
        return self::MARKER_PREFIX."{$hours} h retention]";
    }

    /** Whether a stored prompt is the note this command left in place of the text. */
    public static function removed(string $prompt): bool
    {
        return str_starts_with($prompt, self::MARKER_PREFIX);
    }

    public function handle(PromptArchiver $archiver): int
    {
        $hours = (int) config('discussion.prompt_retention_hours');

        if ($hours <= 0) {
            $this->info('Prompt retention is off, nothing removed.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours);

        try {
            $archived = $archiver->archiveOlderThan($cutoff);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$archived} prompt texts archived.");

        // Only calls without a project get the note; a project whose archiving failed keeps its texts.
        $removed = PromptLog::query()
            ->whereDoesntHave('jobLog', fn ($job) => $job->whereNotNull('project_id'))
            ->where('created_at', '<', $cutoff)
            ->whereNull('prompt_archive_id')
            ->where('prompt', 'not like', self::MARKER_PREFIX.'%')
            ->update(['prompt' => self::marker($hours)]);

        $this->info("{$removed} prompt texts removed.");
        $this->info("{$this->pruneUploads($hours)} left-behind uploads deleted.");

        if ($archiver->failedProjects > 0) {
            $this->warn("{$archiver->failedProjects} projects could not be archived; their prompt texts were kept (see the log).");

            return self::FAILURE;
        }

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
