<?php

namespace App\Discussion\Logging;

use App\Console\Commands\PrunePrompts;
use App\Models\PromptArchive;
use App\Models\PromptLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Moves the prompt texts of calls older than a cutoff into one PromptArchive per project. */
class PromptArchiver
{
    private const CHUNK = 500;

    /** Projects whose archiving failed in the last archiveOlderThan() run; their prompt texts are untouched. */
    public int $failedProjects = 0;

    /** @param  bool|null  $zstd  null = ask the runtime; a parameter so the abort can be tested */
    public function __construct(private ?bool $zstd = null) {}

    /**
     * @return int number of prompt texts archived
     *
     * @throws RuntimeException before any prompt is touched when ext-zstd is missing
     */
    public function archiveOlderThan(CarbonInterface $cutoff): int
    {
        if (! ($this->zstd ?? PromptArchive::available())) {
            throw new RuntimeException('ext-zstd is missing: prompts cannot be archived.');
        }

        $archived = 0;
        $this->failedProjects = 0;

        foreach ($this->due($cutoff)->distinct()->pluck('job_logs.project_id') as $projectId) {
            try {
                $archived += $this->archiveProject((int) $projectId, $cutoff);
            } catch (Throwable $e) {
                report($e);
                $this->failedProjects++;
            }
        }

        return $archived;
    }

    protected function archiveProject(int $projectId, CarbonInterface $cutoff): int
    {
        return DB::transaction(function () use ($projectId, $cutoff): int {
            $texts = $this->due($cutoff)->where('job_logs.project_id', $projectId)->pluck('prompt_logs.prompt', 'prompt_logs.id')->all();

            if ($texts === []) {
                return 0;
            }

            $archive = PromptArchive::packing($projectId, $texts);
            $archive->save();

            foreach (array_chunk(array_keys($texts), self::CHUNK) as $ids) {
                PromptLog::whereIn('id', $ids)->whereNull('prompt_archive_id')->update(['prompt' => '', 'prompt_archive_id' => $archive->id]);
            }

            return count($texts);
        });
    }

    /** Calls with a project, still holding their text (not archived, not replaced by the old note). */
    private function due(CarbonInterface $cutoff): Builder
    {
        return PromptLog::query()
            ->join('job_logs', 'job_logs.id', '=', 'prompt_logs.job_log_id')
            ->where('prompt_logs.created_at', '<', $cutoff)
            ->whereNull('prompt_logs.prompt_archive_id')
            ->where('prompt_logs.prompt', '!=', '')
            ->where('prompt_logs.prompt', 'not like', PrunePrompts::MARKER_PREFIX.'%')
            ->whereNotNull('job_logs.project_id');
    }
}
