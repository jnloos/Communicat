<?php

namespace Tests\Unit\StudyExport;

use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use App\Services\StudyExport\TurnTokenSums;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A turn's token figure is a sum only when every call of the turn reported it. */
class TurnTokenSumsTest extends TestCase
{
    use RefreshDatabase;

    private function job(Project $run, int $turn): JobLog
    {
        return JobLog::create(['job_class' => 'x', 'project_id' => $run->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => $turn]);
    }

    private function promptLog(JobLog $job, ?int $in, ?int $out, ?int $reasoning): void
    {
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => $in, 'tokens_out' => $out, 'tokens_reasoning' => $reasoning]);
    }

    public function test_sums_per_job_null_where_a_call_did_not_report_and_no_entry_without_calls(): void
    {
        $run = Project::factory()->create();
        $reported = $this->job($run, 1);
        $this->promptLog($reported, 10, 2, 64);
        $this->promptLog($reported, 5, 3, null);
        $silent = $this->job($run, 2);
        $this->promptLog($silent, null, null, null);
        $withoutCalls = $this->job($run, 3);

        $sums = (new TurnTokenSums)->of(collect([$reported->id, $silent->id, $withoutCalls->id]));

        $this->assertSame(['tokens_in' => 15, 'tokens_out' => 5, 'tokens_reasoning' => null], $sums[$reported->id]);
        $this->assertSame(['tokens_in' => null, 'tokens_out' => null, 'tokens_reasoning' => null], $sums[$silent->id]);
        $this->assertArrayNotHasKey($withoutCalls->id, $sums);
    }
}
