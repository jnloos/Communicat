<?php
namespace App\Services\Dependencies;

use App\Models\Contributor;
use App\Models\Project;
use Illuminate\Support\Facades\Concurrency;
use OpenAI;
use function Laravel\Prompts\confirm;

abstract class PromptingStrategy
{
    protected Project $project;
    protected OpenAI\Client $client;
    protected string $model;

    public function __construct(Project $project) {
        $this->project = $project;
        $apiKey = config('apis.openai.api_key');
        $this->model = config('apis.openai.model');
        $this->client = OpenAI::client($apiKey);
    }

    // Acts as a factory for dynamic binding
    public static function forProject(Project $project): static {
        return new static($project);
    }

    public function sendPrompt(string $prompt): string {
        return $this->client->responses()->create([
            'model' => $this->model,
            'input' => $prompt
        ])->outputText;
    }

    public function sendPrompts(array $prompts): array {
        $apiKey = config('apis.openai.api_key');
        $model  = $this->model;

        $tasks = [];
        foreach ($prompts as $prompt) {
            $tasks[] = static function () use ($apiKey, $model, $prompt) {
                $client = OpenAI::client($apiKey);

                return $client->responses()->create([
                    'model' => $model,
                    'input' => $prompt,
                ])->outputText;
            };
        }

        return Concurrency::run($tasks);
    }

    // Do request and save in the database
    public function genAssistantSummary(): void {
        // Prepare data
        $params = [];
        $params['project'] = $this->project->asPromptArray();
        $prompt = view('prompts.assistant-summary', $params)->render();

        // Send Request
        $response = json_decode($this->sendPrompt($prompt), true);
        if (empty($response['summary'])) {
            return;
        }

        $assistant = Contributor::assistant();
        $this->project->addMessage($response['summary'], contributor: $assistant);
    }

    // Do request and save in the database
    abstract public function genExpertSummaries(): void;

    // Do request and save in the database
    abstract public function genNextMessage(): void;

    protected function needsExpertSummariesRefresh(): bool {
        $numMsg = $this->project->messages()->count();
        $freq = $this->project->summary_frequency;
        return $numMsg > 0 && $numMsg % $freq == 0;
    }
}
