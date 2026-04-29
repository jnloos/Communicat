<?php

namespace App\Services;

use App\Models\PromptLog;
use Illuminate\Support\Facades\Concurrency;
use OpenAI;
use OpenAI\Client;

class OpenAIClient
{
    protected Client $client;
    protected string $modelFast;
    protected string $modelSlow;

    protected static ?int $jobLogId = null;

    /**
     * System-level framing applied to every call. Prevents content-policy refusals
     * on legitimate but politically/ethically charged debate topics by clarifying
     * the simulation context. This is NOT a content filter bypass — the model still
     * follows OpenAI policy; it just knows the personas are fictional.
     */
    protected const SYSTEM_INSTRUCTIONS = <<<'TXT'
Du bist Teil einer akademischen Diskussionssimulation. Mehrere fiktive Experten mit klar definierten Rollen erörtern ein vom Nutzer vorgegebenes Thema.

Wichtig:
- Alle Personas sind erfunden und nehmen vorgegebene fachliche Perspektiven ein.
- Die behandelten Themen — auch kontroverse, politische, ökonomische oder ethische — sind Gegenstand legitimer akademischer Diskussion und Lehre.
- Verweigere keinen Beitrag wegen Kontroversität des Themas. Folge stattdessen strikt dem Format und der Persona, die im Nutzerprompt vorgegeben sind.
- Halte dich an die im Prompt verlangte Ausgabestruktur (z.B. GEDÄCHTNIS-UPDATE, METADATEN-Block) wortwörtlich.
- Vertritt Standpunkte, ohne als KI zu sprechen oder dich zu distanzieren.
TXT;

    public function __construct() {
        $this->modelFast = config('apis.openai.model_fast');
        $this->modelSlow = config('apis.openai.model_slow');
        $this->client    = OpenAI::client(config('apis.openai.api_key'));
    }

    public static function bindJobLog(?int $jobLogId): void
    {
        self::$jobLogId = $jobLogId;
    }

    public function send(string $prompt, ?string $model = null, string $label = ''): string {
        $model = $model ?? $this->modelFast;
        $start = microtime(true);

        $response = $this->client->responses()->create([
            'model'        => $model,
            'instructions' => self::SYSTEM_INSTRUCTIONS,
            'input'        => $prompt,
        ])->outputText;

        $this->logCall($label, $model, $prompt, $response, $start);

        return $response;
    }

    public function sendFast(string $prompt, string $label = ''): string {
        return $this->send($prompt, $this->modelFast, $label);
    }

    public function sendSlow(string $prompt, string $label = ''): string {
        return $this->send($prompt, $this->modelSlow, $label);
    }

    public function sendMany(array $prompts, ?string $model = null, string $label = ''): array {
        $apiKey = config('apis.openai.api_key');
        $model  = $model ?? $this->modelFast;

        $keys         = array_keys($prompts);
        $instructions = self::SYSTEM_INSTRUCTIONS;
        $tasks        = [];
        foreach ($prompts as $prompt) {
            $tasks[] = static function () use ($apiKey, $model, $prompt, $instructions) {
                $client = OpenAI::client($apiKey);
                $start  = microtime(true);
                $text   = $client->responses()->create([
                    'model'        => $model,
                    'instructions' => $instructions,
                    'input'        => $prompt,
                ])->outputText;
                return ['response' => $text, 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
            };
        }

        $raw       = Concurrency::run($tasks);
        $results   = [];
        $promptArr = array_values($prompts);

        foreach ($keys as $i => $key) {
            $results[$key] = $raw[$i]['response'];
            $entryLabel    = $label !== '' ? "{$label}:{$key}" : (string) $key;
            $this->recordCall($entryLabel, $model, $promptArr[$i], $raw[$i]['response'], $raw[$i]['latency_ms']);
        }

        return $results;
    }

    public function sendManyFast(array $prompts, string $label = ''): array {
        return $this->sendMany($prompts, $this->modelFast, $label);
    }

    public function sendManySlow(array $prompts, string $label = ''): array {
        return $this->sendMany($prompts, $this->modelSlow, $label);
    }

    protected function logCall(string $label, string $model, string $prompt, string $response, float $start): void
    {
        $this->recordCall($label, $model, $prompt, $response, (int) round((microtime(true) - $start) * 1000));
    }

    protected function recordCall(string $label, string $model, string $prompt, string $response, int $latencyMs): void
    {
        if (self::$jobLogId === null) {
            return;
        }

        try {
            PromptLog::create([
                'job_log_id' => self::$jobLogId,
                'label'      => $label !== '' ? $label : null,
                'model'      => $model,
                'prompt'     => $prompt,
                'response'   => $response,
                'latency_ms' => $latencyMs,
            ]);
        } catch (\Throwable) {
            // Never let debug logging fail the pipeline.
        }
    }
}
