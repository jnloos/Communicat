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

    /** @param  list<array<string, ?int>>  $calls */
    private function report(array $calls): string
    {
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);

        foreach ($calls as $tokens) {
            PromptLog::create($tokens + ['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'p', 'response' => 'r', 'latency_ms' => 22427]);
        }

        return view('components.debug.job-report', [
            'logs' => collect([$job]),
            'selected' => $job->load('project', 'promptLogs', 'messages'),
            'selectedJobId' => $job->id,
        ])->render();
    }

    /** The text of the turn total's cell for one figure. */
    private function total(string $html, string $column): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $cell = (new \DOMXPath($dom))->query("//*[@data-tokens-total]//*[@data-total='{$column}']")->item(0);
        $this->assertNotNull($cell, "total cell {$column} not found");

        return trim(preg_replace('/\s+/', ' ', $cell->textContent));
    }

    public function test_german_ui_groups_thousands_with_a_dot(): void
    {
        app()->setLocale('de');

        $html = $this->report([['tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => 64]]);

        $this->assertStringContainsString('Tokens ein', $html);
        $this->assertSame('1.200', $this->total($html, 'tokens_in'));
        $this->assertStringContainsString('22.427 ms', $html);
    }

    public function test_every_call_has_its_columns_and_the_turn_total_sums_them(): void
    {
        app()->setLocale('en');

        $html = $this->report([
            ['tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => 64],
            ['tokens_in' => 800, 'tokens_out' => 100, 'tokens_reasoning' => 64],
        ]);

        $this->assertStringContainsString('Tokens in', $html);
        $this->assertStringContainsString('1,200', $html);
        $this->assertSame('2,000', $this->total($html, 'tokens_in'));
        $this->assertSame('400', $this->total($html, 'tokens_out'));
        $this->assertSame('128', $this->total($html, 'tokens_reasoning'));
    }

    public function test_a_figure_only_some_calls_reported_is_a_lower_bound_with_a_tooltip(): void
    {
        app()->setLocale('en');

        $html = $this->report([
            ['tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => 64],
            ['tokens_in' => 800, 'tokens_out' => 100, 'tokens_reasoning' => null],
        ]);

        $total = $this->total($html, 'tokens_reasoning');
        $this->assertStringStartsWith('≥ 64', $total);
        $this->assertStringContainsString(__('debug.detail.tokens_partial'), $total, 'The tooltip sits in the total cell.');
    }

    public function test_a_figure_no_call_reported_is_a_dash_never_zero(): void
    {
        app()->setLocale('en');

        $html = $this->report([
            ['tokens_in' => 1200, 'tokens_out' => 300, 'tokens_reasoning' => null],
            ['tokens_in' => 800, 'tokens_out' => 100, 'tokens_reasoning' => null],
        ]);

        $this->assertSame('–', $this->total($html, 'tokens_reasoning'));
        $this->assertStringContainsString('aria-label="'.e(__('debug.detail.tokens_missing')).'"', $html);
    }
}
