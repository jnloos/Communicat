<?php

namespace App\Study\Control;

use App\Discussion\Values\JobStatus;
use App\Models\JobLog;

/**
 * How badly one run is stuck, read from its newest job_logs rows. Every turn is a job
 * of its own, so attempts() knows nothing; job_logs survives restarts and is the truth.
 * A turn counts as spoken when it has words (the counting rule in CLAUDE.md), so a turn
 * that spoke and then failed in Summarize breaks both chains.
 */
final readonly class RunHealth
{
    private function __construct(
        /** Newest turns in a row that failed without speaking. */
        public int $failedInARow,
        /** Newest turns in a row without words: failed, interrupted, a jev_outage halt. */
        public int $stalledInARow,
    ) {}

    public static function of(int $projectId): self
    {
        $rows = JobLog::where('project_id', $projectId)
            ->latest('id')
            ->limit(config('study.run.max_stalled_in_a_row'))
            ->get(['status', 'words']);

        $silent = $rows->takeWhile(fn (JobLog $log) => $log->words === null);

        return new self(
            failedInARow: $silent->takeWhile(fn (JobLog $log) => $log->status === JobStatus::Failed)->count(),
            stalledInARow: $silent->count(),
        );
    }

    /** No later turn is expected to get further: the run is given up (unless the study is paused). */
    public function exhausted(): bool
    {
        return $this->failedInARow >= config('study.run.max_failed_in_a_row')
            || $this->stalledInARow >= config('study.run.max_stalled_in_a_row');
    }

    /** 60 s doubling per stalled turn, at most 15 minutes. */
    public function backoffSeconds(): int
    {
        return min(60 * 2 ** (max($this->stalledInARow, 1) - 1), 900);
    }
}
