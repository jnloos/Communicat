<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Values\CallStatus;
use App\Discussion\Values\JobStatus;
use App\Discussion\Values\Purpose;
use App\Events\JobLogged;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogStatusCastTest extends TestCase
{
    use RefreshDatabase;

    private function job(): JobLog
    {
        return JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => JobStatus::Success, 'started_at' => now(), 'turn_index' => 1]);
    }

    public function test_a_job_status_reads_as_an_enum_and_is_stored_as_its_string(): void
    {
        $job = $this->job();

        $this->assertSame(JobStatus::Success, $job->fresh()->status);
        $this->assertSame('success', DB::table('job_logs')->where('id', $job->id)->value('status'));
    }

    public function test_a_call_status_and_purpose_read_as_enums_and_are_stored_as_strings(): void
    {
        $call = PromptLog::create(['job_log_id' => $this->job()->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'purpose' => Purpose::Judge, 'status' => CallStatus::Failed]);

        $this->assertSame(CallStatus::Failed, $call->fresh()->status);
        $this->assertSame(Purpose::Judge, $call->fresh()->purpose);
        $this->assertSame(['status' => 'failed', 'purpose' => 'judge'], (array) DB::table('prompt_logs')->where('id', $call->id)->first(['status', 'purpose']));
    }

    public function test_the_broadcast_still_sends_the_status_as_a_string(): void
    {
        $this->assertSame('success', (new JobLogged($this->job()->fresh()))->broadcastWith()['status']);
    }
}
