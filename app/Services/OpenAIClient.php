<?php

namespace App\Services;

use Illuminate\Support\Facades\Concurrency;
use OpenAI;
use OpenAI\Client;

class OpenAIClient
{
    protected Client $client;
    protected string $modelFast;
    protected string $modelSlow;

    public function __construct() {
        $this->modelFast = config('apis.openai.model_fast');
        $this->modelSlow = config('apis.openai.model_slow');
        $this->client    = OpenAI::client(config('apis.openai.api_key'));
    }

    public function send(string $prompt, ?string $model = null): string {
        return $this->client->responses()->create([
            'model' => $model ?? $this->modelFast,
            'input' => $prompt,
        ])->outputText;
    }

    public function sendFast(string $prompt): string {
        return $this->send($prompt, $this->modelFast);
    }

    public function sendSlow(string $prompt): string {
        return $this->send($prompt, $this->modelSlow);
    }

    public function sendMany(array $prompts): array {
        $apiKey = config('apis.openai.api_key');
        $model  = $this->modelFast;

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
}
