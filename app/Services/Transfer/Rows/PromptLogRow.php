<?php

namespace App\Services\Transfer\Rows;

use App\Discussion\Values\CallStatus;
use App\Discussion\Values\Purpose;
use App\Models\PromptArchive;
use App\Models\PromptLog;
use Closure;
use RuntimeException;

/**
 * One prompt_logs row inside a bundle's project file, nested under its turn,
 * in both directions. The one place the table's columns are listed for the
 * transfer; TransferredColumnsTest fails when a new column is neither carried nor
 * listed in NOT_CARRIED.
 */
final class PromptLogRow
{
    /** Columns the file carries under another key. */
    public const RENAMED = ['expert' => 'expert_id'];

    /** Columns the file does not carry, and why. */
    public const NOT_CARRIED = [
        'id' => 'a call is nested under its turn and needs no key',
        'job_log_id' => 'a call is nested under its turn',
        'prompt_archive_id' => 'the bundle carries the unpacked text; an import stores it unarchived',
        'updated_at' => 'bookkeeping only',
    ];

    /** @param  Closure(?int): ?string  $uuid  expert id => uuid */
    public static function toArray(PromptLog $log, Closure $uuid): array
    {
        if ($log->prompt_archive_id !== null && ! PromptArchive::available()) {
            throw new RuntimeException("Prompt call {$log->id} is archived and ext-zstd is missing: the export would lose its prompt text.");
        }

        return [
            'label' => $log->label,
            'model' => $log->model,
            'prompt' => $log->prompt_text,
            'response' => $log->response,
            'latency_ms' => $log->latency_ms,
            'provider' => $log->provider,
            'checkpoint' => $log->checkpoint,
            'model_served' => $log->model_served,
            'config' => $log->config,
            'purpose' => $log->purpose?->value,
            'expert' => $uuid($log->expert_id),
            'reasoning' => $log->reasoning,
            'tokens_in' => $log->tokens_in,
            'tokens_out' => $log->tokens_out,
            'tokens_reasoning' => $log->tokens_reasoning,
            'tokens_cached' => $log->tokens_cached,
            'status' => $log->status?->value,
            'error' => $log->error,
            'created_at' => Timestamp::export($log->created_at),
        ];
    }

    /** @param  Closure(?string): ?int  $expert  uuid => expert id here */
    public static function attributes(array $prompt, int $jobLogId, Closure $expert): array
    {
        return [
            'job_log_id' => $jobLogId,
            'prompt_archive_id' => null,
            'label' => $prompt['label'] ?? null,
            'model' => (string) ($prompt['model'] ?? ''),
            'prompt' => (string) ($prompt['prompt'] ?? ''),
            'response' => (string) ($prompt['response'] ?? ''),
            'latency_ms' => $prompt['latency_ms'] ?? null,
            'provider' => $prompt['provider'] ?? null,
            'checkpoint' => $prompt['checkpoint'] ?? null,
            'model_served' => $prompt['model_served'] ?? null,
            'config' => $prompt['config'] ?? null,
            'purpose' => isset($prompt['purpose']) ? Purpose::from($prompt['purpose']) : null,
            'expert_id' => $expert($prompt['expert'] ?? null),
            'reasoning' => $prompt['reasoning'] ?? null,
            'tokens_in' => $prompt['tokens_in'] ?? null,
            'tokens_out' => $prompt['tokens_out'] ?? null,
            'tokens_reasoning' => $prompt['tokens_reasoning'] ?? null,
            'tokens_cached' => $prompt['tokens_cached'] ?? null,
            'status' => CallStatus::from($prompt['status'] ?? CallStatus::Ok->value),
            'error' => $prompt['error'] ?? null,
            'created_at' => Timestamp::import($prompt['created_at'] ?? null) ?? now(),
        ];
    }
}
