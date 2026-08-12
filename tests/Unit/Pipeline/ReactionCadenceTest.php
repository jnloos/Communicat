<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Support\ModeratorService;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The reaction cadence schedules short reactions instead of hoping for them.
 *
 * The brevity signal alone fired on 52 of 58 logged turns, so binding the
 * reaction role to it directly would turn every turn into a reaction. The
 * cadence counter is what keeps them occasional.
 */
class ReactionCadenceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        config(['discussion.reaction_cadence' => 3]);

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $user->id,
        ]));
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    private function route(array $context): \App\Services\PromptingPipeline\Data\Directive
    {
        $json = json_encode([
            'candidates' => ["E{$this->expert->id}"],
            'directive' => [
                'role' => 'vertiefen',
                'agenda_step' => 'divergenz',
                'convergence_intent' => 'x',
                'hand_back_to_user' => $context['hand_back'] ?? false,
            ],
            'reasoning' => 'r',
        ]);

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->once()->andReturn($json);

        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->once()->andReturn('prompt');

        return (new ModeratorService($this->project, $client, $prompts))
            ->route('', $context)['directive'];
    }

    private function withSetting(int $turnsSinceReaction): void
    {
        $this->project->settings = ['turns_since_reaction' => $turnsSinceReaction];
        $this->project->save();
    }

    public function test_a_reaction_is_scheduled_once_the_cadence_is_due(): void
    {
        $this->withSetting(3);

        $directive = $this->route(['brevity_streak' => true]);

        $this->assertSame('kurz_reagieren', $directive->role);
    }

    public function test_no_reaction_while_the_cadence_has_not_elapsed(): void
    {
        $this->withSetting(1);

        $this->assertSame('vertiefen', $this->route(['brevity_streak' => true])->role);
    }

    public function test_no_reaction_when_the_recent_turns_were_not_all_long(): void
    {
        $this->withSetting(9);

        $this->assertSame('vertiefen', $this->route(['brevity_streak' => false])->role);
    }

    /**
     * A hand-off owes the human a real question, never a one-line reaction.
     */
    public function test_a_hand_off_is_never_converted_into_a_reaction(): void
    {
        $this->withSetting(9);

        $directive = $this->route(['brevity_streak' => true, 'hand_back' => true]);

        $this->assertTrue($directive->handBackToUser);
        $this->assertNotSame('kurz_reagieren', $directive->role);
    }

    public function test_the_cadence_can_be_disabled(): void
    {
        config(['discussion.reaction_cadence' => 0]);
        $this->withSetting(99);

        $this->assertSame('vertiefen', $this->route(['brevity_streak' => true])->role);
    }
}
