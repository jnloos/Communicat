<?php

namespace App\Services\Transfer\Rows;

use App\Models\JobLog;
use App\Services\Transfer\ExpertRenumbering;
use Closure;

/**
 * One job_logs row inside a bundle's project file, in both directions. The
 * one place the table's columns are listed for the transfer, so export and
 * import cannot drift apart; TransferredColumnsTest fails when a new column is neither
 * carried nor listed in NOT_CARRIED.
 */
final class JobLogRow
{
    /** Columns the file carries under another key. */
    public const RENAMED = ['key' => 'id', 'expert' => 'expert_id'];

    /** Columns the file does not carry, and why. */
    public const NOT_CARRIED = [
        'project_id' => 'the row sits inside its project file',
        'created_at' => 'set from started_at on import',
        'updated_at' => 'bookkeeping only',
    ];

    /** @param  Closure(?int): ?string  $uuid  expert id => uuid */
    public static function toArray(JobLog $job, Closure $uuid): array
    {
        return [
            'key' => $job->id,
            'job_class' => $job->job_class,
            'status' => $job->status,
            'payload' => $job->payload,
            'turn_index' => $job->turn_index,
            'expert' => $uuid($job->expert_id),
            'seat' => $job->seat,
            'words' => $job->words,
            'chars' => $job->chars,
            'thought_words' => $job->thought_words,
            'thought_chars' => $job->thought_chars,
            'reasoning_tokens' => $job->reasoning_tokens,
            'selection' => $job->selection,
            'error' => $job->error,
            'started_at' => Timestamp::export($job->started_at),
            'finished_at' => Timestamp::export($job->finished_at),
        ];
    }

    /** @param  Closure(?string): ?int  $expert  uuid => expert id here */
    public static function attributes(array $job, int $projectId, Closure $expert, ExpertRenumbering $renumbering): array
    {
        $started = Timestamp::import($job['started_at'] ?? null) ?? now();

        return [
            'project_id' => $projectId,
            'job_class' => (string) ($job['job_class'] ?? ''),
            'status' => (string) ($job['status'] ?? 'failed'),
            'payload' => $job['payload'] ?? null,
            'turn_index' => $job['turn_index'] ?? null,
            'expert_id' => $expert($job['expert'] ?? null),
            'seat' => $job['seat'] ?? null,
            'words' => $job['words'] ?? null,
            'chars' => $job['chars'] ?? null,
            'thought_words' => $job['thought_words'] ?? null,
            'thought_chars' => $job['thought_chars'] ?? null,
            'reasoning_tokens' => $job['reasoning_tokens'] ?? null,
            'selection' => $renumbering->selection($job['selection'] ?? null),
            'error' => $job['error'] ?? null,
            'started_at' => $started,
            'finished_at' => Timestamp::import($job['finished_at'] ?? null),
            'created_at' => $started,
        ];
    }
}
