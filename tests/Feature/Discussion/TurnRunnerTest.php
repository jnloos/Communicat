<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class TurnRunnerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var Expert[] */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class, JobLogged::class]);

        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ich will widersprechen.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Alice antwortet kurz.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);

        $this->project = Project::factory()->create(['pipeline' => 'RoundRobinPipeline']);
        $this->experts = Expert::factory()->count(2)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    /**
     * The role each seat carried is the study's manipulation, so it is frozen
     * with the run rather than reconstructed afterwards from the personas --
     * a persona can be renamed or re-described later, this snapshot cannot.
     */
    public function test_the_snapshot_records_who_sat_where_under_which_role(): void
    {
        app(TurnRunner::class)->run($this->project);

        $cast = $this->project->fresh()->run_config['cast'];

        $this->assertCount(2, $cast);
        $this->assertSame([1, 2], array_column($cast, 'seat'), 'ordered by seat');
        $this->assertSame(
            $this->project->contributingExperts()->pluck('role')->all(),
            array_column($cast, 'role'),
        );
        $this->assertSame(
            $this->project->contributingExperts()->pluck('name')->all(),
            array_column($cast, 'name'),
        );
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
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('verweigert'));

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

    public function test_a_turn_that_speaks_but_then_fails_in_summarize_still_consumes_budget(): void
    {
        // Force Summarize to actually run: pending participant messages must
        // reach summarize_threshold once this turn's own message is added
        // (same trick as SummarizeTest).
        config(['discussion.summarize_threshold' => 3, 'discussion.summarize_oldest' => 1]);
        $this->project->update(['turn_budget' => 1]);
        $this->project->addMessage('eins', $this->experts[0]);
        $this->project->addMessage('zwei', $this->experts[1]);
        FakeAgents::fails(SummarizeAgent::class, new RuntimeException('Zusammenfassung nicht erreichbar'));

        $result = app(TurnRunner::class)->run($this->project);

        $this->assertTrue($result->stop);
        $this->assertSame('failed', $result->reason);

        $log = JobLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->words);
        $this->assertStringContainsString('Zusammenfassung nicht erreichbar', $log->error);
        $this->assertSame('Alice antwortet kurz.', Message::whereNotNull('expert_id')->latest('id')->first()->content);

        $second = app(TurnRunner::class)->run($this->project);

        $this->assertTrue($second->stop);
        $this->assertSame('turn_budget', $second->reason);
        $this->assertSame(1, JobLog::count());
    }

    public function test_the_first_turn_snapshots_the_run_configuration(): void
    {
        app(TurnRunner::class)->run($this->project);

        $config = $this->project->fresh()->run_config;

        $this->assertSame('RoundRobinPipeline', $config['pipeline']);
        $this->assertCount(5, $config['stages']);
        $this->assertSame($this->project->model, $config['model']['key']);
        $this->assertSame(config('ai.models')[$this->project->model]['model'], $config['model']['model']);
        $this->assertSame(config('discussion.summarize_threshold'), $config['summarize_threshold']);
        $this->assertSame(config('discussion.summarize_oldest'), $config['summarize_oldest']);
        $this->assertArrayNotHasKey('changes', $config);
        $this->assertStringContainsString('discussion simulation', $config['system_prompt']);
    }

    public function test_the_snapshot_records_the_projects_own_summarize_settings(): void
    {
        $this->project->update(['summarize_threshold' => 12, 'summarize_oldest' => 5]);

        app(TurnRunner::class)->run($this->project);

        $config = $this->project->fresh()->run_config;

        $this->assertSame(12, $config['summarize_threshold']);
        $this->assertSame(5, $config['summarize_oldest']);
    }
}
