<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\PersistMessage;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Contribution;
use App\Discussion\Values\Selection;
use App\Llm\LlmResponse;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersistMessageTest extends TestCase
{
    use RefreshDatabase;

    private function response(): LlmResponse
    {
        return new LlmResponse('roh', '', 'm', 'm-1', 1, 1, 0, 1, 'completed');
    }

    public function test_saves_the_contribution_with_its_adjacency_metadata(): void
    {
        $project = Project::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);
        $log = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'running', 'started_at' => now()]);

        $payload = new TurnPayload($project, 1, $log->id);
        $payload->select(new Selection($alice, 'RoundRobinSelector'));
        $payload->contribute(new Contribution('Bob, wie meinst du das?', "E{$bob->id}", Message::PAIR_FRAGE_ANTWORT, $this->response()));

        (new PersistMessage)->handle($payload, fn (TurnPayload $p) => $p);

        $message = $payload->message()->fresh();

        $this->assertSame('Bob, wie meinst du das?', $message->content);
        $this->assertSame($alice->id, $message->expert_id);
        $this->assertSame($log->id, $message->job_log_id);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $message->adjacency_pair_type);
        $this->assertTrue($message->adjacencyPartner->is($bob));
    }

    public function test_a_plenum_contribution_gets_the_default_pair_type_and_no_partner(): void
    {
        $project = Project::factory()->create();
        $alice = Expert::factory()->create();
        $project->addContributingExpert($alice);

        $payload = new TurnPayload($project, 1);
        $payload->select(new Selection($alice, 'RoundRobinSelector'));
        $payload->contribute(new Contribution('Ich sehe das anders.', null, null, $this->response()));

        (new PersistMessage)->handle($payload, fn (TurnPayload $p) => $p);

        $message = $payload->message()->fresh();

        $this->assertSame(Message::PAIR_BEITRAG_DISKUSSION, $message->adjacency_pair_type);
        $this->assertNull($message->adjacency_partner_id);
    }
}
