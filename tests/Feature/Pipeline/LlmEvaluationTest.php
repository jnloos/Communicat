<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\DiscussionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Optional live evaluation against real model output. Never runs in default CI.
 *
 * Usage: RUN_LLM_EVAL=1 php artisan test --filter=LlmEvaluationTest
 */
class LlmEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_LLM_EVAL')) {
            $this->markTestSkipped('Set RUN_LLM_EVAL=1 to run live LLM evaluation.');
        }
    }

    #[Group('llm-evaluation')]
    public function test_live_discussion_metrics_report(): void
    {
        $user = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Live Eval',
            'description' => 'Evaluation fixture',
            'settings' => [],
            'user_id' => $user->id,
        ]));

        $experts = Expert::factory()->count(2)->create();
        foreach ($experts as $expert) {
            $project->addContributingExpert($expert);
        }

        $project->addMessage('Was ist eure Einschätzung zu kurzen Antworten im Chat?', $user);

        $result = (new DiscussionPipeline($project))->run();

        $latest = $project->messages()->whereNotNull('expert_id')->latest('id')->first();

        $metrics = [
            'stop' => $result['stop'] ?? false,
            'content_length' => mb_strlen($latest?->content ?? ''),
            'has_steuerung_marker' => str_contains($latest?->content ?? '', '---STEUERUNG---') === false,
            'ends_with_question' => str_ends_with(trim($latest?->content ?? ''), '?'),
            'named_reference' => collect($experts)->contains(
                fn (Expert $e) => str_contains($latest?->content ?? '', $e->name)
            ),
        ];

        $this->assertNotNull($latest, 'Expected at least one expert message from live pipeline run.');
        $this->assertIsArray($metrics);

        fwrite(STDERR, PHP_EOL.json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }
}
