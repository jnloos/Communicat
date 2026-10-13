<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PrunePrompts;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrunePromptsTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $age): PromptLog
    {
        $job = JobLog::create(['job_class' => 'x', 'project_id' => Project::factory()->create()->id, 'status' => 'success', 'started_at' => now()]);
        $log = PromptLog::create(['job_log_id' => $job->id, 'model' => 'm', 'prompt' => 'Long prompt', 'response' => 'Answer', 'tokens_in' => 10, 'tokens_out' => 5]);
        $log->forceFill(['created_at' => now()->sub($age)])->save();

        return $log;
    }

    public function test_it_removes_old_prompt_texts_and_keeps_everything_else(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('25 hours');
        $fresh = $this->log('1 hour');

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame(PrunePrompts::marker(24), $old->fresh()->prompt);
        $this->assertSame('Answer', $old->fresh()->response);
        $this->assertSame(10, $old->fresh()->tokens_in);
        $this->assertSame('Long prompt', $fresh->fresh()->prompt);
    }

    public function test_zero_hours_keeps_every_prompt(): void
    {
        config(['discussion.prompt_retention_hours' => 0]);
        $old = $this->log('30 days');

        $this->artisan('prompts:prune')->assertSuccessful();

        $this->assertSame('Long prompt', $old->fresh()->prompt);
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        config(['discussion.prompt_retention_hours' => 24]);
        $old = $this->log('2 days');

        $this->artisan('prompts:prune');
        $updatedAt = $old->fresh()->updated_at;
        $this->artisan('prompts:prune')->expectsOutputToContain('0 prompt');

        $this->assertEquals($updatedAt, $old->fresh()->updated_at);
    }
}
