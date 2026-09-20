<?php

namespace Tests\Feature\Discussion;

use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class TurnRunnerTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    /** @var Expert[] */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class, JobLogged::class]);

        $this->llm = (new FakeLlmClient)
            ->push('think', 'GEDANKE: Ich will widersprechen.')
            ->push('speak', "Alice antwortet kurz.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create(['pipeline' => 'RoundRobinPipeline']);
        $this->experts = Expert::factory()->count(2)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    public function test_a_turn_produces_a_message_and_its_measurement(): void
    {
        $result = app(TurnRunner::class)->run($this->project);

        $this->assertFalse($result->stop);

        $message = Message::whereNotNull('expert_id')->sole();
        $this->assertSame('Alice antwortet kurz.', $message->content);

        $log = JobLog::sole();
        $this->assertSame('success', $log->status);
        $this->assertSame(1, $log->turn_index);
        $this->assertSame($this->experts[0]->id, $log->expert_id);
        $this->assertSame(1, $log->seat);
        $this->assertSame(3, $log->words);
        $this->assertSame(21, $log->chars);
        $this->assertSame(3, $log->thought_words);
        $this->assertSame('RoundRobinSelector', $log->selection['selector']);
        $this->assertSame($log->id, $message->job_log_id);
        $this->assertNotNull($log->finished_at);
    }

    public function test_every_llm_call_of_the_turn_is_logged_against_the_job_log(): void
    {
        app(TurnRunner::class)->run($this->project);

        $log = JobLog::sole();

        $this->assertSame(
            ["think:{$this->experts[0]->id}", "speak:{$this->experts[0]->id}"],
            PromptLog::where('job_log_id', $log->id)->orderBy('id')->pluck('label')->all(),
        );
    }

    public function test_consecutive_turns_rotate_and_count_up(): void
    {
        app(TurnRunner::class)->run($this->project);
        app(TurnRunner::class)->run($this->project);

        $this->assertSame([1, 2], JobLog::orderBy('id')->pluck('turn_index')->all());
        $this->assertSame(
            [$this->experts[0]->id, $this->experts[1]->id],
            JobLog::orderBy('id')->pluck('expert_id')->all(),
        );
    }

    public function test_a_failing_stage_is_recorded_and_stops_the_loop(): void
    {
        $this->llm->failOn('speak', 'verweigert', 'refusal');

        $result = app(TurnRunner::class)->run($this->project);

        $this->assertTrue($result->stop);
        $this->assertSame('failed', $result->reason);

        $log = JobLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('verweigert', $log->error);
        $this->assertSame($this->experts[0]->id, $log->expert_id);
        $this->assertNull($log->words);
        $this->assertSame(0, Message::whereNotNull('expert_id')->count());
        $this->assertSame(1, PromptLog::where('status', 'failed')->count());
    }

    public function test_stops_without_a_log_when_nobody_can_speak(): void
    {
        $empty = Project::factory()->create();

        $result = app(TurnRunner::class)->run($empty);

        $this->assertTrue($result->stop);
        $this->assertSame('no_candidates', $result->reason);
        $this->assertSame(0, JobLog::count());
    }

    public function test_stops_once_the_turn_budget_is_spent(): void
    {
        $this->project->update(['turn_budget' => 1]);

        $this->assertFalse(app(TurnRunner::class)->run($this->project)->stop);

        $result = app(TurnRunner::class)->run($this->project);

        $this->assertTrue($result->stop);
        $this->assertSame('turn_budget', $result->reason);
        $this->assertSame(1, JobLog::count());
    }

    public function test_the_first_turn_snapshots_the_run_configuration(): void
    {
        app(TurnRunner::class)->run($this->project);

        $config = $this->project->fresh()->run_config;

        $this->assertSame('RoundRobinPipeline', $config['pipeline']);
        $this->assertCount(5, $config['stages']);
        $this->assertSame('fake-model', $config['model']['model']);
        $this->assertSame(config('discussion.history_keep'), $config['history_keep']);
        $this->assertStringContainsString('Diskussionssimulation', $config['system_prompt']);
    }
}
