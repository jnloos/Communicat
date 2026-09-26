<?php

namespace Tests\Feature;

use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use App\Services\ProjectTransfer\ProjectExport;
use App\Services\ProjectTransfer\ProjectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_round_trip_keeps_pipeline_model_seats_and_memory(): void
    {
        $owner = User::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();

        $source = Project::factory()->create([
            'user_id' => $owner->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'gemini-2.5-pro',
            'summarize_threshold' => 30, 'summarize_oldest' => 10,
        ]);
        $source->addContributingExpert($bob);
        $source->addContributingExpert($alice);
        $first = $source->addMessage('Alt', $bob);
        $source->addMessage('Neu', $alice);
        $source->update(['long_term_memory' => 'Bisher: X.', 'summarized_until_message_id' => $first->id]);
        Summary::create(['project_id' => $source->id, 'expert_id' => $alice->id, 'content' => 'Alices Gedanke.']);

        $data = (new ProjectExport)->toArray($source->fresh());

        $this->assertSame(8, $data['schema_version']);
        $this->assertArrayNotHasKey('settings', $data['project']);

        $copy = app(ProjectImporter::class)->import($data, $owner)['project']->fresh();

        $this->assertSame('RoundRobinPipeline', $copy->pipeline);
        $this->assertSame('gemini-2.5-pro', $copy->model);
        $this->assertSame(30, $copy->summarize_threshold);
        $this->assertSame(10, $copy->summarize_oldest);
        $this->assertSame('Bisher: X.', $copy->long_term_memory);
        $this->assertSame([$bob->id, $alice->id], $copy->contributingExperts()->pluck('id')->all());
        $this->assertSame([1, 2], $copy->contributingExperts()->map(fn (Expert $e) => $e->pivot->seat)->all());
        $this->assertSame('Alt', $copy->messages()->find($copy->summarized_until_message_id)->content);
        $this->assertSame('Alices Gedanke.', Summary::where('project_id', $copy->id)->value('content'));
        $this->assertNull($copy->run_config);
        $this->assertTrue($copy->users()->whereKey($owner->id)->exists());
    }

    /**
     * What makes a run a run: the seed the tie-breaker draws from, the budget
     * that stops it, and the frozen record of what it meant. Without these an
     * archived run is indistinguishable from a fresh project carrying the same
     * messages.
     */
    public function test_a_round_trip_keeps_the_run_settings(): void
    {
        $owner = User::factory()->create();

        $source = Project::factory()->create([
            'user_id' => $owner->id,
            'pipeline' => 'RoundRobinPipeline',
            'model' => 'gemini-2.5-pro',
            'seed' => 777,
            'turn_budget' => 12,
            'run_config' => ['pipeline' => 'RoundRobinPipeline', 'system_prompt' => 'frozen'],
        ]);

        $copy = app(ProjectImporter::class)->import((new ProjectExport)->toArray($source->fresh()), $owner)['project']->fresh();

        $this->assertSame(777, $copy->seed);
        $this->assertSame(12, $copy->turn_budget);
        $this->assertSame(['pipeline' => 'RoundRobinPipeline', 'system_prompt' => 'frozen'], $copy->run_config);
    }

    /**
     * TurnRunner freezes run_config once and never again. A copy that kept the
     * original's snapshot after falling back to a default pipeline would keep
     * logging under a configuration it is not running, so the snapshot is
     * dropped and the next turn writes an honest one.
     */
    public function test_an_unknown_pipeline_drops_the_frozen_snapshot(): void
    {
        $owner = User::factory()->create();

        $copy = app(ProjectImporter::class)->import([
            'schema_version' => ProjectExport::SCHEMA_VERSION,
            'project' => [
                'title' => 'Archived run',
                'pipeline' => 'APipelineThisInstallDoesNotHave',
                'model' => 'gemini-2.5-pro',
                'seed' => 555,
                'run_config' => ['pipeline' => 'APipelineThisInstallDoesNotHave', 'system_prompt' => 'frozen'],
            ],
        ], $owner)['project']->fresh();

        $this->assertNotSame('APipelineThisInstallDoesNotHave', $copy->pipeline, 'the pipeline fell back');
        $this->assertNull($copy->run_config, 'so the snapshot must not survive');
        $this->assertSame(555, $copy->seed, 'the seed is a parameter, not a snapshot, and still applies');
    }

    /** An export written before schema 8 carries none of this; the copy gets a fresh seed. */
    public function test_an_older_export_still_imports_and_gets_its_own_seed(): void
    {
        $owner = User::factory()->create();

        $copy = app(ProjectImporter::class)->import([
            'schema_version' => 7,
            'project' => ['title' => 'Older export', 'pipeline' => 'RoundRobinPipeline'],
        ], $owner)['project']->fresh();

        $this->assertNotNull($copy->seed);
        $this->assertNull($copy->turn_budget);
        $this->assertNull($copy->run_config);
    }

    public function test_a_round_trip_keeps_a_valid_addressee_drops_a_stray_one_and_leaves_none_alone(): void
    {
        $owner = User::factory()->create();
        [$alice, $bob, $carol] = Expert::factory()->count(3)->create()->all();

        $source = Project::factory()->create(['user_id' => $owner->id]);
        $source->addContributingExpert($alice);
        $source->addContributingExpert($bob);
        $source->addContributingExpert($carol);

        // Addressed to a contributing expert: must survive the round trip.
        $toAlice = $source->addMessage('An Alice.', $bob);
        $toAlice->addressee_expert_id = $alice->id;
        $toAlice->save();

        // No addressee at all: must still have none.
        $toNobody = $source->addMessage('An die Gruppe.', $alice);

        // Addressed to Carol while she was still a contributor, but she leaves
        // the project before export: the export's expert list no longer
        // carries her, so her id cannot be re-linked on import.
        $toCarol = $source->addMessage('An Carol.', $bob);
        $toCarol->addressee_expert_id = $carol->id;
        $toCarol->save();
        $source->removeContributingExpert($carol);

        $data = (new ProjectExport)->toArray($source->fresh());

        $copy = app(ProjectImporter::class)->import($data, $owner)['project']->fresh();

        $copiedToAlice = $copy->messages()->where('content', 'An Alice.')->sole();
        $copiedToNobody = $copy->messages()->where('content', 'An die Gruppe.')->sole();
        $copiedToCarol = $copy->messages()->where('content', 'An Carol.')->sole();

        // Experts are shared rows, not recreated per project, so the id stays
        // the same on a valid re-link — assert on the resolved relation, not
        // just the raw column, so a blindly-copied id could not pass this.
        $this->assertTrue($alice->is($copiedToAlice->addressee));
        $this->assertNull($copiedToNobody->addressee_expert_id);
        $this->assertNull($copiedToCarol->addressee_expert_id);
    }

    public function test_unknown_pipeline_and_model_fall_back_to_the_defaults(): void
    {
        $owner = User::factory()->create();

        $copy = app(ProjectImporter::class)->import([
            'project' => ['title' => 'Alt', 'description' => 'd', 'pipeline' => 'GonePipeline', 'model' => 'gone'],
        ], $owner)['project'];

        $this->assertSame(config('discussion.default_pipeline'), $copy->pipeline);
        $this->assertSame(config('ai.default_model'), $copy->model);
    }

    public function test_an_export_without_the_summarize_settings_still_imports(): void
    {
        $owner = User::factory()->create();

        // Shape of a schema-4 export: the two keys did not exist yet.
        $copy = app(ProjectImporter::class)->import([
            'schema_version' => 4,
            'project' => ['title' => 'Alt', 'description' => 'd', 'pipeline' => 'RoundRobinPipeline', 'model' => 'gemini-2.5-pro'],
        ], $owner)['project'];

        $this->assertNull($copy->summarize_threshold);
        $this->assertNull($copy->summarize_oldest);
        $this->assertSame(config('discussion.summarize_threshold'), $copy->summarizeThreshold());
        $this->assertSame(config('discussion.summarize_oldest'), $copy->summarizeOldest());
    }
}
