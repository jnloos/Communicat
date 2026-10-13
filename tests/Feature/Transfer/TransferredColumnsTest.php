<?php

namespace Tests\Feature\Transfer;

use App\Models\JobLog;
use App\Models\PromptLog;
use App\Services\Transfer\ExpertRenumbering;
use App\Services\Transfer\Rows\JobLogRow;
use App\Services\Transfer\Rows\PromptLogRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A column added to job_logs or prompt_logs must be carried by the bundle or
 * listed, with its reason, in the row class's NOT_CARRIED -- never left out of
 * archives without anybody deciding so.
 */
class TransferredColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_job_logs_column_is_exported_or_listed_as_not_carried(): void
    {
        $exported = array_keys(JobLogRow::toArray(new JobLog, fn () => null));

        $this->assertSameColumns('job_logs', [...$this->columnsOf($exported, JobLogRow::RENAMED), ...array_keys(JobLogRow::NOT_CARRIED)]);
    }

    public function test_every_prompt_logs_column_is_exported_or_listed_as_not_carried(): void
    {
        $exported = array_keys(PromptLogRow::toArray(new PromptLog, fn () => null));

        $this->assertSameColumns('prompt_logs', [...$this->columnsOf($exported, PromptLogRow::RENAMED), ...array_keys(PromptLogRow::NOT_CARRIED)]);
    }

    public function test_an_import_writes_every_column_but_the_new_id_and_updated_at(): void
    {
        $job = JobLogRow::attributes([], 1, fn () => null, new ExpertRenumbering([]));
        $prompt = PromptLogRow::attributes([], 1, fn () => null);

        $this->assertSameColumns('job_logs', [...array_keys($job), 'id', 'updated_at']);
        $this->assertSameColumns('prompt_logs', [...array_keys($prompt), 'id', 'updated_at']);
    }

    /** @return list<string> */
    private function columnsOf(array $keys, array $renamed): array
    {
        return array_map(fn (string $key) => $renamed[$key] ?? $key, $keys);
    }

    private function assertSameColumns(string $table, array $columns): void
    {
        $expected = Schema::getColumnListing($table);
        sort($expected);
        sort($columns);

        $this->assertSame($expected, $columns, "The bundle's columns for {$table} differ from the table.");
    }
}
