<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\RunCosts;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.models' => [
            'priced' => ['provider' => 'openai', 'model' => 'gpt-5', 'price' => ['input' => 1.25, 'output' => 10.00]],
            'unpriced' => ['provider' => 'gemini', 'model' => 'gemini-3.8-flash', 'price' => null],
        ]]);
    }

    private function logCall(Project $project, string $provider, string $model, ?int $in, ?int $out, string $status = 'ok'): PromptLog
    {
        $job = JobLog::create([
            'job_class' => 'Test',
            'project_id' => $project->id,
            'status' => 'success',
            'started_at' => now(),
        ]);

        return PromptLog::create([
            'job_log_id' => $job->id,
            'provider' => $provider,
            'model' => $model,
            'prompt' => 'p',
            'response' => 'r',
            'tokens_in' => $in,
            'tokens_out' => $out,
            'status' => $status,
        ]);
    }

    public function test_it_prices_input_and_output_per_million_tokens(): void
    {
        $project = Project::factory()->create();
        $this->logCall($project, 'openai', 'gpt-5', 1_000_000, 100_000);

        $report = (new RunCosts)->forProject($project);

        // 1.25 for the million in, 1.00 for the hundred thousand out.
        $this->assertEqualsWithDelta(2.25, $report->usd(), 0.000001);
        $this->assertFalse($report->hasUnpricedModels());
    }

    /**
     * Reasoning tokens ride inside tokens_out and are billed at the output rate.
     * Adding them again would charge them twice.
     */
    public function test_it_does_not_add_reasoning_tokens_on_top_of_the_output(): void
    {
        $project = Project::factory()->create();
        $log = $this->logCall($project, 'openai', 'gpt-5', 0, 100_000);
        $log->update(['tokens_reasoning' => 90_000]);

        $this->assertEqualsWithDelta(1.00, (new RunCosts)->forProject($project)->usd(), 0.000001);
    }

    /** A refused or filtered answer was generated, and is billed. */
    public function test_it_counts_failed_calls_too(): void
    {
        $project = Project::factory()->create();
        $this->logCall($project, 'openai', 'gpt-5', 1_000_000, 0, status: 'failed');

        $report = (new RunCosts)->forProject($project);

        $this->assertSame(1, $report->calls());
        $this->assertEqualsWithDelta(1.25, $report->usd(), 0.000001);
    }

    public function test_an_unpriced_model_yields_null_rather_than_zero(): void
    {
        $project = Project::factory()->create();
        $this->logCall($project, 'gemini', 'gemini-3.8-flash', 1_000_000, 1_000_000);

        $report = (new RunCosts)->forProject($project);

        $this->assertNull($report->lines[0]->usd);
        $this->assertTrue($report->hasUnpricedModels());
        $this->assertSame(0.0, $report->usd());
    }

    /** A project whose model changed mid-run gets one line per model, not one blended figure. */
    public function test_it_reports_one_line_per_model(): void
    {
        $project = Project::factory()->create();
        $this->logCall($project, 'openai', 'gpt-5', 1_000_000, 0);
        $this->logCall($project, 'gemini', 'gemini-3.8-flash', 1_000_000, 0);

        $report = (new RunCosts)->forProject($project);

        $this->assertCount(2, $report->lines);
        $this->assertSame(2_000_000, $report->tokensIn());
    }

    public function test_since_a_mark_covers_only_the_calls_made_after_it(): void
    {
        $project = Project::factory()->create();
        $costs = new RunCosts;

        $this->logCall($project, 'openai', 'gpt-5', 1_000_000, 0);
        $mark = $costs->mark();
        $this->logCall($project, 'openai', 'gpt-5', 2_000_000, 0);

        $this->assertEqualsWithDelta(2.50, $costs->forProjectSince($project, $mark)->usd(), 0.000001);
        $this->assertEqualsWithDelta(3.75, $costs->forProject($project)->usd(), 0.000001);
    }

    public function test_a_project_without_calls_reports_nothing(): void
    {
        $this->assertTrue((new RunCosts)->forProject(Project::factory()->create())->isEmpty());
    }

    /** Another project's calls must never land in this project's bill. */
    public function test_it_does_not_count_another_projects_calls(): void
    {
        $mine = Project::factory()->create();
        $this->logCall(Project::factory()->create(), 'openai', 'gpt-5', 1_000_000, 0);

        $this->assertTrue((new RunCosts)->forProject($mine)->isEmpty());
    }

    /** A transport failure logs no tokens; it must not break the sum. */
    public function test_null_token_counts_count_as_nothing(): void
    {
        $project = Project::factory()->create();
        $this->logCall($project, 'openai', 'gpt-5', null, null);

        $this->assertSame(0, (new RunCosts)->forProject($project)->tokensIn());
        $this->assertSame(0.0, (new RunCosts)->forProject($project)->usd());
    }
}
