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

        $this->assertStringContainsString('Tokens ein 1.200', $html);
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

        $this->assertStringContainsString('Tokens in 1,200', $html);
        $this->assertStringContainsString('Tokens out 300', $html);
        $this->assertStringContainsString('Reasoning tokens 64', $html);
        $this->assertStringContainsString('Reasoning tokens –', $html);

        $total = $this->jobTotal($html);
        $this->assertStringContainsString('Tokens in 2,000', $total);
        $this->assertStringContainsString('Tokens out 400', $total);
        $this->assertStringContainsString('Reasoning tokens 64', $total);
        $this->assertStringNotContainsString('Reasoning tokens –', $total);
    }

    public function test_job_total_shows_a_dash_when_no_call_reported_reasoning(): void
    {
        app()->setLocale('en');
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => null]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'tokens_in' => 800, 'tokens_out' => 100, 'tokens_reasoning' => null]);

        $html = view('components.debug.job-report', [
            'logs' => collect([$job]),
            'selected' => $job->load('project', 'promptLogs', 'messages'),
            'selectedJobId' => $job->id,
        ])->render();

        $total = $this->jobTotal($html);
        $this->assertStringContainsString('Reasoning tokens –', $total);
        $this->assertStringNotContainsString('Reasoning tokens 0', $total);
    }

    /** The job total is the one element marked data-tokens-total; per-call lines sit elsewhere. */
    private function jobTotal(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))->query('//*[@data-tokens-total]')->item(0);
        $this->assertNotNull($node, 'job total block not found');

        return $node->textContent;
    }
}
