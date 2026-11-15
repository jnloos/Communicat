<?php

namespace App\Study\Control;

use App\Discussion\Values\JobStatus;
use App\Models\JobLog;

/**
 * How badly one run is stuck, read from its newest job_logs rows. Every turn is a job
 * of its own, so attempts() knows nothing; job_logs survives restarts and is the truth.
 * A turn counts as spoken when it has words (the counting rule in CLAUDE.md), so a turn
 * that spoke and then failed in Summarize breaks every chain.
 */
final readonly class RunHealth
{
    /** job_logs.error of a turn a dead worker or a timeout left running, closed by the run's next job. */
    public const ABANDONED = 'abandoned: the worker died or the turn timed out';

    private function __construct(
        /** Newest turns in a row that failed without speaking. */
        public int $failedInARow,
        /** Newest turns in a row that were abandoned mid-turn. */
        public int $abandonedInARow,
        /** Newest turns in a row without words: failed, interrupted, abandoned, a jev_outage halt. */
        public int $stalledInARow,
    ) {}

    public static function of(int $projectId): self
    {
        $rows = JobLog::where('project_id', $projectId)
            ->latest('id')
            ->limit(self::lookback())
            ->get(['status', 'words', 'error']);

        $silent = $rows->takeWhile(fn (JobLog $log) => $log->words === null);

        return new self(
            failedInARow: $silent->takeWhile(fn (JobLog $log) => $log->status === JobStatus::Failed)->count(),
            abandonedInARow: $silent->takeWhile(fn (JobLog $log) => $log->error === self::ABANDONED)->count(),
            stalledInARow: $silent->count(),
        );
    }

    /** How many rows are read: enough for the largest give-up limit, or that chain could never reach it. */
    public static function lookback(): int
    {
        return max(config('study.run.max_failed_in_a_row'), config('study.run.max_abandoned_in_a_row'));
    }

    /**
     * No later turn is expected to get further: the run is given up (unless the study is paused).
     * Only model failures and dead turns count; an infrastructure interruption or a Jev outage never does.
     */
    public function exhausted(): bool
    {
        return $this->failedInARow >= config('study.run.max_failed_in_a_row') || $this->abandonedTooOften();
    }

    public function abandonedTooOften(): bool
    {
        return $this->abandonedInARow >= config('study.run.max_abandoned_in_a_row');
    }

    /** 60 s doubling per stalled turn, at most 15 minutes. */
    public function backoffSeconds(): int
    {
        return min(60 * 2 ** (max($this->stalledInARow, 1) - 1), 900);
    }
}
