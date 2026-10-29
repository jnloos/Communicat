<?php

namespace Tests\Feature\Transfer;

use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\User;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\ProjectTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** model_served and tokens_cached travel with the run; bundles from before them still import, as null. */
class PromptLogServedModelTransferTest extends TestCase
{
    use RefreshDatabase;

    private function runWith(User $owner, array $call): Project
    {
        $project = Project::factory()->create(['user_id' => $owner->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'omniroute-gpt-5.6-luna']);
        $job = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'words' => 2]);
        PromptLog::create(['job_log_id' => $job->id, 'model' => 'openai/gpt-5-mini', 'prompt' => 'p', 'response' => 'r', 'purpose' => 'think'] + $call);

        return $project;
    }

    public function test_the_two_columns_survive_a_round_trip_including_null(): void
    {
        $owner = User::factory()->create();
        $a = $this->runWith($owner, ['model_served' => 'gpt-5-mini-2025-08-07', 'tokens_cached' => 0]);
        $b = $this->runWith($owner, []);
        $dataA = (new ProjectTransfer)->export($a);
        $dataB = (new ProjectTransfer)->export($b);
        $a->delete();
        $b->delete();

        $this->assertSame('gpt-5-mini-2025-08-07', $dataA['jobs'][0]['prompts'][0]['model_served']);

        $copyA = (new ProjectTransfer)->import(json_decode(json_encode($dataA), true), $owner, new ImportReport);
        $copyB = (new ProjectTransfer)->import(json_decode(json_encode($dataB), true), $owner, new ImportReport);

        $callA = PromptLog::whereHas('jobLog', fn ($q) => $q->where('project_id', $copyA->id))->sole();
        $callB = PromptLog::whereHas('jobLog', fn ($q) => $q->where('project_id', $copyB->id))->sole();
        $this->assertSame('gpt-5-mini-2025-08-07', $callA->model_served);
        $this->assertSame(0, $callA->tokens_cached);
        $this->assertNull($callB->model_served);
        $this->assertNull($callB->tokens_cached);
    }

    public function test_a_bundle_written_before_the_columns_still_imports_with_null(): void
    {
        $owner = User::factory()->create();
        $source = $this->runWith($owner, ['model_served' => 'x', 'tokens_cached' => 5]);
        $data = json_decode(json_encode((new ProjectTransfer)->export($source)), true);
        $source->delete();
        unset($data['jobs'][0]['prompts'][0]['model_served'], $data['jobs'][0]['prompts'][0]['tokens_cached']);

        $report = new ImportReport;
        $copy = (new ProjectTransfer)->import($data, $owner, $report);

        $this->assertNotNull($copy);
        $call = PromptLog::whereHas('jobLog', fn ($q) => $q->where('project_id', $copy->id))->sole();
        $this->assertNull($call->model_served);
        $this->assertNull($call->tokens_cached);
    }

    public function test_a_wrongly_typed_value_fails_the_project_instead_of_being_stored(): void
    {
        $owner = User::factory()->create();
        $source = $this->runWith($owner, []);
        $data = json_decode(json_encode((new ProjectTransfer)->export($source)), true);
        $source->delete();
        $data['jobs'][0]['prompts'][0]['tokens_cached'] = 'many';

        $report = new ImportReport;

        $this->assertNull((new ProjectTransfer)->import($data, $owner, $report));
        $this->assertSame(0, PromptLog::count());
    }
}
