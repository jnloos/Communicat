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
            'user_id' => $owner->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'gemini',
            'summarize_threshold' => 30, 'summarize_oldest' => 10,
        ]);
        $source->addContributingExpert($bob);
        $source->addContributingExpert($alice);
        $first = $source->addMessage('Alt', $bob);
        $source->addMessage('Neu', $alice);
        $source->update(['long_term_memory' => 'Bisher: X.', 'summarized_until_message_id' => $first->id]);
        Summary::create(['project_id' => $source->id, 'expert_id' => $alice->id, 'content' => 'Alices Gedanke.']);

        $data = (new ProjectExport)->toArray($source->fresh());

        $this->assertSame(5, $data['schema_version']);
        $this->assertArrayNotHasKey('settings', $data['project']);

        $copy = app(ProjectImporter::class)->import($data, $owner)['project']->fresh();

        $this->assertSame('RoundRobinPipeline', $copy->pipeline);
        $this->assertSame('gemini', $copy->model);
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
            'project' => ['title' => 'Alt', 'description' => 'd', 'pipeline' => 'RoundRobinPipeline', 'model' => 'gemini'],
        ], $owner)['project'];

        $this->assertNull($copy->summarize_threshold);
        $this->assertNull($copy->summarize_oldest);
        $this->assertSame(config('discussion.summarize_threshold'), $copy->summarizeThreshold());
        $this->assertSame(config('discussion.summarize_oldest'), $copy->summarizeOldest());
    }
}
