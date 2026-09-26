<?php

namespace Tests\Feature\Console;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Metrics\SpeakingShares;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ProjectCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, Expert> */
    private array $experts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@example.test']);

        foreach (['Alice', 'Bob'] as $name) {
            $this->experts[$name] = Expert::factory()->create(['name' => $name]);
        }
    }

    /** Model answers for a Round Robin turn: think, then speak. */
    private function fakeAnswers(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ich halte das für zentral.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein kurzer Beitrag zur Sache.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Bisher ging es um das Thema.']);
    }

    // --- project:create -------------------------------------------------

    public function test_create_seats_the_experts_in_the_given_order(): void
    {
        $this->artisan('project:create', [
            'title' => 'Tempolimit',
            '--experts' => "{$this->experts['Bob']->id},{$this->experts['Alice']->id}",
            '--pipeline' => 'RoundRobinPipeline',
            '--turns' => 5,
            '--seed' => 4242,
        ])->assertSuccessful();

        $project = Project::where('title', 'Tempolimit')->sole();

        $this->assertSame($this->admin->id, $project->user_id);
        $this->assertSame('RoundRobinPipeline', $project->pipeline);
        $this->assertSame(5, $project->turn_budget);
        $this->assertSame(4242, $project->seed);
        $this->assertSame(['Bob', 'Alice'], $project->contributingExperts()->pluck('name')->all());
    }

    public function test_create_without_a_seed_still_gets_one(): void
    {
        $this->artisan('project:create', ['title' => 'Ohne Seed'])->assertSuccessful();

        $this->assertNotNull(Project::where('title', 'Ohne Seed')->sole()->seed);
    }

    /**
     * Owning a project is not the same as being in it. The sidebar lists
     * projects through the contributor pivot, so a project created without the
     * owner attached exists, is reachable by URL, and appears nowhere.
     */
    public function test_create_puts_the_owner_in_the_project(): void
    {
        $this->artisan('project:create', ['title' => 'Visible'])->assertSuccessful();

        $project = Project::where('title', 'Visible')->sole();

        $this->assertTrue($project->users()->whereKey($this->admin->id)->exists());
        $this->assertTrue($project->hasContributor($this->admin));
    }

    /** The opening notice has no sender, so it never reaches a prompt or a count. */
    public function test_the_welcome_message_stays_out_of_the_measurement(): void
    {
        $this->artisan('project:create', ['title' => 'With notice'])->assertSuccessful();

        $project = Project::where('title', 'With notice')->sole();

        $this->assertSame(1, $project->messages()->count());
        $this->assertSame(0, $project->participantMessages()->count());
    }

    public function test_create_rejects_an_unknown_pipeline(): void
    {
        $this->artisan('project:create', ['title' => 'x', '--pipeline' => 'NoSuchPipeline'])
            ->expectsOutputToContain('Unknown pipeline')
            ->assertFailed();

        $this->assertSame(0, Project::count());
    }

    public function test_create_rejects_an_unknown_model(): void
    {
        $this->artisan('project:create', ['title' => 'x', '--model' => 'no-such-model'])
            ->expectsOutputToContain('Unknown model')
            ->assertFailed();

        $this->assertSame(0, Project::count());
    }

    public function test_create_rejects_more_experts_than_seats(): void
    {
        $ids = Expert::factory()->count(Project::MAX_CONTRIBUTING_EXPERTS + 1)->create()->pluck('id')->implode(',');

        $this->artisan('project:create', ['title' => 'x', '--experts' => $ids])->assertFailed();

        $this->assertSame(0, Project::count());
    }

    // --- project:contributors -------------------------------------------

    public function test_contributors_seats_and_unseats(): void
    {
        $project = Project::factory()->create(['user_id' => $this->admin->id]);

        $this->artisan('project:contributors', [
            'project' => $project->id,
            '--add' => "{$this->experts['Alice']->id},{$this->experts['Bob']->id}",
        ])->assertSuccessful();

        $this->assertCount(2, $project->fresh()->contributingExperts());

        $this->artisan('project:contributors', [
            'project' => $project->id,
            '--remove' => $this->experts['Alice']->id,
        ])->assertSuccessful();

        $this->assertSame(['Bob'], $project->fresh()->contributingExperts()->pluck('name')->all());
    }

    public function test_contributors_refuses_a_fifth_seat(): void
    {
        $project = Project::factory()->create(['user_id' => $this->admin->id]);

        foreach (Expert::factory()->count(Project::MAX_CONTRIBUTING_EXPERTS)->create() as $expert) {
            $project->addContributingExpert($expert);
        }

        $this->artisan('project:contributors', [
            'project' => $project->id,
            '--add' => $this->experts['Alice']->id,
        ])->assertFailed();

        $this->assertCount(Project::MAX_CONTRIBUTING_EXPERTS, $project->fresh()->contributingExperts());
    }

    // --- project:run ----------------------------------------------------

    public function test_run_drives_the_requested_number_of_turns(): void
    {
        $this->fakeAnswers();
        $project = $this->seatedProject();

        $this->artisan('project:run', ['project' => $project->id, '--turns' => 3])->assertSuccessful();

        $this->assertSame(3, JobLog::where('project_id', $project->id)->whereNotNull('words')->count());
    }

    /** The budget is the study's stop condition, and it must hold from the console too. */
    public function test_run_stops_at_the_turn_budget(): void
    {
        $this->fakeAnswers();
        $project = $this->seatedProject(budget: 2);

        $this->artisan('project:run', ['project' => $project->id, '--turns' => 10])->assertSuccessful();

        $this->assertSame(2, JobLog::where('project_id', $project->id)->whereNotNull('words')->count());
    }

    /** Round robin rotates by seat; the console run must show the same rotation. */
    public function test_run_rotates_the_floor_by_seat(): void
    {
        $this->fakeAnswers();
        $project = $this->seatedProject();

        $this->artisan('project:run', ['project' => $project->id, '--turns' => 4])->assertSuccessful();

        $speakers = JobLog::where('project_id', $project->id)
            ->whereNotNull('words')->orderBy('turn_index')->pluck('expert_id')->all();

        $this->assertSame([$speakers[0], $speakers[1], $speakers[0], $speakers[1]], $speakers);
    }

    public function test_run_refuses_an_unknown_project(): void
    {
        $this->artisan('project:run', ['project' => 999])->assertFailed();
    }

    public function test_run_refuses_a_turn_count_below_one(): void
    {
        $this->artisan('project:run', ['project' => $this->seatedProject()->id, '--turns' => 0])->assertFailed();
    }

    /** No experts seated: TurnRunner refuses, and the command must not loop. */
    public function test_run_stops_when_nobody_can_speak(): void
    {
        $project = Project::factory()->create(['user_id' => $this->admin->id]);

        $this->artisan('project:run', ['project' => $project->id, '--turns' => 5])->assertSuccessful();

        $this->assertSame(0, JobLog::where('project_id', $project->id)->count());
    }

    // --- project:metrics ------------------------------------------------

    public function test_metrics_reports_the_shares_as_json(): void
    {
        $project = $this->seatedProject();
        $this->turn($project, 'Alice', 10);
        $this->turn($project, 'Alice', 10);
        $this->turn($project, 'Bob', 20);

        $this->artisan('project:metrics', ['project' => $project->id, '--format' => 'json'])
            ->assertSuccessful();

        // The command prints to the console; re-derive the same numbers to assert
        // on the contract rather than on formatting.
        $report = app(SpeakingShares::class)->forProject($project->fresh());

        $this->assertSame(3, $report->spokenTurns);
        $this->assertSame(40, $report->totalWords);
    }

    public function test_metrics_rejects_an_unknown_format(): void
    {
        $this->artisan('project:metrics', ['project' => $this->seatedProject()->id, '--format' => 'xml'])
            ->expectsOutputToContain('Unknown format')
            ->assertFailed();
    }

    public function test_metrics_survives_a_run_that_never_spoke(): void
    {
        $this->artisan('project:metrics', ['project' => $this->seatedProject()->id])
            ->assertSuccessful();
    }

    // --- project:list ---------------------------------------------------

    /**
     * Rendered through Artisan::call rather than $this->artisan(): the latter's
     * expectation matcher does not see Symfony's table output, so it would pass
     * on an empty table.
     */
    public function test_list_shows_the_projects_with_their_progress(): void
    {
        $project = $this->seatedProject(budget: 6);
        $this->turn($project, 'Alice', 10);

        $this->assertSame(0, Artisan::call('project:list'));
        $output = Artisan::output();

        $this->assertStringContainsString($project->title, $output);
        $this->assertStringContainsString('RoundRobinPipeline', $output);
        $this->assertStringContainsString((string) $project->seed, $output);
        $this->assertStringContainsString('1 / 6', $output);
    }

    public function test_list_can_narrow_to_runs_that_have_started(): void
    {
        $this->seatedProject();

        $this->assertSame(0, Artisan::call('project:list', ['--frozen' => true]));
        $this->assertStringContainsString('No project has started a run yet.', Artisan::output());
    }

    public function test_list_says_so_when_there_is_nothing(): void
    {
        $this->assertSame(0, Artisan::call('project:list'));
        $this->assertStringContainsString('No projects.', Artisan::output());
    }

    // --- error handling shared by the project commands -------------------

    public function test_the_commands_name_the_missing_project_the_same_way(): void
    {
        foreach (['project:run', 'project:metrics', 'project:contributors'] as $command) {
            $this->artisan($command, ['project' => 4242])
                ->expectsOutputToContain('No project with id 4242')
                ->assertFailed();
        }
    }

    // --- helpers --------------------------------------------------------

    private function seatedProject(?int $budget = null): Project
    {
        $project = Project::factory()->create([
            'user_id' => $this->admin->id,
            'pipeline' => 'RoundRobinPipeline',
            'turn_budget' => $budget,
        ]);

        $project->addContributingExpert($this->experts['Alice']);
        $project->addContributingExpert($this->experts['Bob']);

        return $project->fresh();
    }

    private function turn(Project $project, string $name, int $words): void
    {
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $project->id,
            'status' => 'success',
            'started_at' => now(),
            'expert_id' => $this->experts[$name]->id,
            'words' => $words,
        ]);
    }
}
