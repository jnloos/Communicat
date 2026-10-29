<?php

namespace Tests\Feature\Debug;

use App\Console\Commands\PrunePrompts;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptArchive;
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
            'selected' => $selected->load('project', 'promptLogs', 'messages'),
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
            'selected' => $open->load('project', 'promptLogs', 'messages'),
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

    public function test_a_json_answer_is_indented_and_a_removed_prompt_says_so(): void
    {
        $job = $this->job();
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => PrunePrompts::marker(24), 'response' => '{"thought":"Ja","priority":4}']);

        $html = $this->render($job);

        $this->assertStringContainsString("{\n    &quot;thought&quot;: &quot;Ja&quot;,\n    &quot;priority&quot;: 4\n}", $html);
        $this->assertStringContainsString(__('debug.detail.prompt_removed'), $html);
        $this->assertStringNotContainsString(e(PrunePrompts::marker(24)), $html);
    }

    public function test_an_archived_prompt_shows_its_text_and_a_broken_archive_says_unreadable(): void
    {
        $job = $this->job();
        $archive = PromptArchive::packing($job->project_id, []);
        $archive->save();
        $call = PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => '', 'response' => 'A', 'prompt_archive_id' => $archive->id]);
        $archive->update(['payload' => PromptArchive::packing($job->project_id, [$call->id => 'Archived words'])->payload]);

        $this->assertStringContainsString('Archived words', $this->render($job));

        $archive->update(['payload' => 'broken']);

        $html = $this->render($job->fresh());
        $this->assertStringNotContainsString('Archived words', $html);
        $this->assertStringContainsString(__('debug.detail.prompt_unreadable'), $html);
        $this->assertStringNotContainsString(__('debug.detail.prompt_removed'), $html);
    }

    public function test_a_failed_job_shows_its_error_in_a_callout(): void
    {
        $job = $this->job(['status' => 'failed', 'error' => 'HTTP 500 from provider']);

        $html = $this->render($job);

        $this->assertMatchesRegularExpression('/data-flux-callout[^>]*>.*HTTP 500 from provider/s', $html);
    }
}
