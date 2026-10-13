<?php

namespace Tests\Feature\Debug;

use App\Console\Commands\PrunePrompts;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobReportViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function render(JobLog $selected, JobLog ...$others): string
    {
        return view('components.debug.job-report', [
            'logs' => collect([...$others, $selected]),
            'selected' => $selected->load('project', 'expert', 'promptLogs', 'messages'),
            'selectedJobId' => $selected->id,
        ])->render();
    }

    private function job(array $attributes = []): JobLog
    {
        return JobLog::create($attributes + ['job_class' => 'App\\Jobs\\TurnRunner', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
    }

    public function test_the_open_job_unfolds_right_under_its_own_row(): void
    {
        $above = $this->job();
        $open = $this->job();
        $below = $this->job();

        $html = view('components.debug.job-report', [
            'logs' => collect([$above, $open, $below]),
            'selected' => $open->load('project', 'expert', 'promptLogs', 'messages'),
            'selectedJobId' => $open->id,
        ])->render();

        $openRow = strpos($html, "wire:key=\"job-{$open->id}\"");
        $detail = strpos($html, "wire:key=\"detail-{$open->id}\"");
        $nextRow = strpos($html, "wire:key=\"job-{$below->id}\"");
        $this->assertTrue($openRow < $detail && $detail < $nextRow, 'The detail sits between its row and the next one.');
    }

    public function test_every_job_has_a_button_that_says_whether_it_is_open(): void
    {
        $closed = $this->job();
        $open = $this->job();

        $html = $this->render($open, $closed);

        $this->assertMatchesRegularExpression('/aria-label="Job #'.$open->id.'"[^>]*aria-expanded="true"|aria-expanded="true"[^>]*aria-label="Job #'.$open->id.'"/', $html);
        $this->assertMatchesRegularExpression('/aria-label="Job #'.$closed->id.'"[^>]*aria-expanded="false"|aria-expanded="false"[^>]*aria-label="Job #'.$closed->id.'"/', $html);
    }

    public function test_the_detail_names_turn_speaker_and_model(): void
    {
        $expert = Expert::factory()->create(['name' => 'Robin']);
        $job = $this->job(['turn_index' => 59, 'expert_id' => $expert->id, 'seat' => 3]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'gpt-5-mini', 'prompt' => 'p', 'response' => 'r']);

        $html = $this->render($job);

        $this->assertStringContainsString('Turn 59', $html);
        $this->assertStringContainsString('Robin, seat 3', $html);
        $this->assertStringContainsString('gpt-5-mini', $html);
    }

    public function test_a_json_answer_is_indented_and_a_removed_prompt_says_so(): void
    {
        $job = $this->job();
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => PrunePrompts::marker(24), 'response' => '{"thought":"Ja","priority":4}']);

        $html = $this->render($job);

        $this->assertStringContainsString("{\n    &quot;thought&quot;: &quot;Ja&quot;,\n    &quot;priority&quot;: 4\n}", $html);
        $this->assertStringContainsString(__('debug.detail.prompt_removed'), $html);
        $this->assertStringNotContainsString(e(PrunePrompts::marker(24)), $html);
    }

    public function test_a_failed_job_shows_its_error_in_a_callout(): void
    {
        $job = $this->job(['status' => 'failed', 'error' => 'HTTP 500 from provider']);

        $html = $this->render($job);

        $this->assertMatchesRegularExpression('/data-flux-callout[^>]*>.*HTTP 500 from provider/s', $html);
    }
}
