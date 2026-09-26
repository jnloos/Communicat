<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\PersistMessage;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Completion;
use App\Discussion\Values\Contribution;
use App\Discussion\Values\Selection;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersistMessageTest extends TestCase
{
    use RefreshDatabase;

    private function completion(): Completion
    {
        return new Completion('roh', '', 1, 1, null);
    }

    public function test_saves_the_contribution_with_its_addressee(): void
    {
        $project = Project::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);
        $log = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'running', 'started_at' => now()]);

        $payload = new TurnPayload($project, 1, $log->id);
        $payload->select(new Selection($alice, 'RoundRobinSelector'));
        $payload->contribute(new Contribution('Bob, wie meinst du das?', "E{$bob->id}", $this->completion()));

        (new PersistMessage)->handle($payload, fn (TurnPayload $p) => $p);

        $message = $payload->message()->fresh();

        $this->assertSame('Bob, wie meinst du das?', $message->content);
        $this->assertSame($alice->id, $message->expert_id);
        $this->assertSame($log->id, $message->job_log_id);
        $this->assertTrue($message->adjacencyPartner->is($bob));
    }

    public function test_a_plenum_contribution_gets_no_addressee(): void
    {
        $project = Project::factory()->create();
        $alice = Expert::factory()->create();
        $project->addContributingExpert($alice);

        $payload = new TurnPayload($project, 1);
        $payload->select(new Selection($alice, 'RoundRobinSelector'));
        $payload->contribute(new Contribution('Ich sehe das anders.', null, $this->completion()));

        (new PersistMessage)->handle($payload, fn (TurnPayload $p) => $p);

        $this->assertNull($payload->message()->fresh()->adjacency_partner_id);
    }
}
