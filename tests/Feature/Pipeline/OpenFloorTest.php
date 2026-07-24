<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Stages\RunOrchestratorInstructions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The deterministic floor helper: the addressee of an open pair (question /
 * address) from the latest expert turn holds the floor next.
 */
class OpenFloorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    private Expert $expert2;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
    }

    private function addressedMessage(string $pairType): Message
    {
        $message = new Message;
        $message->project_id = $this->project->id;
        $message->expert_id = $this->expert1->id;
        $message->content = 'Was hältst du davon, Bob?';
        $message->adjacency_pair_type = $pairType;
        $message->adjacency_partner_type = Expert::class;
        $message->adjacency_partner_id = $this->expert2->id;
        $message->save();

        return $message;
    }

    private function resolveFloor(): ?Expert
    {
        $ctx = new TurnContext($this->project);
        $ctx->latestMessage = $this->project->latestParticipantMessage();

        $stage = new RunOrchestratorInstructions;
        $method = new ReflectionMethod($stage, 'openFloorExpert');
        $method->setAccessible(true);

        return $method->invoke($stage, $ctx);
    }

    public function test_addressed_expert_holds_floor_on_question_pair(): void
    {
        $this->addressedMessage(Message::PAIR_FRAGE_ANTWORT);

        $floor = $this->resolveFloor();

        $this->assertNotNull($floor);
        $this->assertSame($this->expert2->id, $floor->id);
    }

    public function test_no_floor_on_plain_contribution_pair(): void
    {
        $this->addressedMessage(Message::PAIR_BEITRAG_DISKUSSION);

        $this->assertNull($this->resolveFloor());
    }

    public function test_no_floor_when_latest_is_user_message(): void
    {
        $user = $this->project->owner;
        $this->project->addMessage('Eine Nutzerfrage.', $user);

        $this->assertNull($this->resolveFloor());
    }
}
