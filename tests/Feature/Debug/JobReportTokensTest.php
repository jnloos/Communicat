<?php

namespace Tests\Feature\Debug;

use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobReportTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_german_ui_groups_thousands_with_a_dot(): void
    {
        app()->setLocale('de');
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => 64]);

        $html = view('components.debug.job-report', [
            'logs' => collect([$job]),
            'selected' => $job->load('project', 'promptLogs', 'messages'),
            'selectedJobId' => $job->id,
        ])->render();

        $this->assertStringContainsString('Ein 1.200', $html);
    }

    public function test_every_call_and_the_job_show_their_tokens(): void
    {
        app()->setLocale('en');
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => 64]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 800, 'tokens_out' => 100, 'tokens_reasoning' => null]);

        $html = view('components.debug.job-report', [
            'logs' => collect([$job]),
            'selected' => $job->load('project', 'promptLogs', 'messages'),
            'selectedJobId' => $job->id,
        ])->render();

        $this->assertStringContainsString('In 1,200', $html);
        $this->assertStringContainsString('Out 300', $html);
        $this->assertStringContainsString('Reasoning 64', $html);
        $this->assertStringContainsString('Reasoning –', $html);
        $this->assertStringContainsString('In 2,000', $html);
        $this->assertStringContainsString('Out 400', $html);
    }
}
