<?php

namespace App\Services;

use Illuminate\Support\Facades\Concurrency;
use OpenAI;
use OpenAI\Client;

class OpenAIClient
{
    protected Client $client;
    protected string $model;

    public function __construct() {
        $this->model  = config('apis.openai.model');
        $this->client = OpenAI::client(config('apis.openai.api_key'));
    }

    public function send(string $prompt): string {
        return $this->client->responses()->create([
            'model' => $this->model,
            'input' => $prompt,
        ])->outputText;
    }

    public function sendMany(array $prompts): array {
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
}
