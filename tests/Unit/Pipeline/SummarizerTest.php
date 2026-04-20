<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\OpenAIClient;
use App\Services\PromptBuilder;
use App\Services\Summarizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SummarizerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn() => Project::create([
            'title'       => 'Test',
            'description' => 'Test',
            'settings'    => [],
            'user_id'     => $this->user->id,
        ]));
        $this->expert = Expert::factory()->create();
        $this->project->addContributingExpert($this->expert);
    }

    private function addExpertMessages(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->project->addMessage("Nachricht $i", $this->expert);
        }
    }

    public function test_maybe_run_does_nothing_below_threshold(): void
    {
        $this->project->settings = ['buffer_threshold' => 20, 'buffer_keep' => 8];
        $this->project->save();
        $this->addExpertMessages(10);

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldNotReceive('sendSlow');
        $prompts->shouldNotReceive('shortenChat');

        (new Summarizer($this->project, $client, $prompts))->maybeRun();
    }

    public function test_maybe_run_does_nothing_at_threshold(): void
    {
        $this->project->settings = ['buffer_threshold' => 5, 'buffer_keep' => 2];
        $this->project->save();
        $this->addExpertMessages(5);

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldNotReceive('sendSlow');

        (new Summarizer($this->project, $client, $prompts))->maybeRun();
    }

    public function test_maybe_run_compresses_when_over_threshold(): void
    {
        $this->project->settings = ['buffer_threshold' => 5, 'buffer_keep' => 2];
        $this->project->save();
        $this->addExpertMessages(6);

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->once()->andReturn('Komprimierte Zusammenfassung.');
        $prompts->shouldReceive('shortenChat')->once()->andReturn('prompt');

        (new Summarizer($this->project, $client, $prompts))->maybeRun();

        $this->project->refresh();
        $this->assertSame('Komprimierte Zusammenfassung.', $this->project->settings['chat_summary']);
    }

    public function test_maybe_run_advances_watermark_to_last_compressed_message(): void
    {
        $this->project->settings = ['buffer_threshold' => 5, 'buffer_keep' => 2];
        $this->project->save();
        $this->addExpertMessages(6); // compress 4, keep 2

        $fourthMessage = $this->project->messages()
            ->whereNotNull('expert_id')
            ->orderBy('id')
            ->skip(3)->first();

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->andReturn('Summary');
        $prompts->shouldReceive('shortenChat')->andReturn('prompt');

        (new Summarizer($this->project, $client, $prompts))->maybeRun();

        $this->project->refresh();
        $this->assertSame($fourthMessage->id, $this->project->settings['last_summarized_id']);
    }

    public function test_maybe_run_respects_existing_watermark(): void
    {
        // Only messages after the watermark count toward the threshold
        $this->project->settings = ['buffer_threshold' => 5, 'buffer_keep' => 2];
        $this->project->save();
        $this->addExpertMessages(4);

        $watermark = $this->project->messages()->whereNotNull('expert_id')->orderBy('id')->first()->id;
        $this->project->settings = ['buffer_threshold' => 5, 'buffer_keep' => 2, 'last_summarized_id' => $watermark];
        $this->project->save();

        $this->addExpertMessages(2); // only 3 unsummarized → below threshold of 5

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldNotReceive('sendSlow');

        (new Summarizer($this->project, $client, $prompts))->maybeRun();
    }

    public function test_maybe_run_passes_correct_messages_to_shortenChat(): void
    {
        $this->project->settings = ['buffer_threshold' => 3, 'buffer_keep' => 1];
        $this->project->save();
        $this->addExpertMessages(4); // compress 3, keep 1

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->andReturn('Summary');
        $prompts->shouldReceive('shortenChat')
            ->withArgs(fn($p, $msgs) => count($msgs) === 3)
            ->once()
            ->andReturn('prompt');

        (new Summarizer($this->project, $client, $prompts))->maybeRun();
    }
}
