# Generische Turn-Pipeline: Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die fest verdrahtete, OpenAI-gebundene Moderator-Pipeline durch eine generische Turn-Pipeline ersetzen (provider-neutraler LLM-Client, frei kombinierbare Stages, Pipeline-Auswahl je Projekt, Messung im Runner) und dabei die im Review gefundenen Bestandsfehler beheben.

**Architecture:** Eine Pipeline ist eine Klasse mit `stages(): array`; Laravels `Pipeline` schickt einen `TurnPayload` hindurch. Der `TurnRunner` löst die Pipeline über die `PipelineRegistry` (Ordner-Scan) auf und protokolliert jeden Turn. Alle LLM-Aufrufe laufen über das Interface `LlmClient` mit je einem Adapter für OpenAI, Anthropic und Gemini; ein Decorator schreibt `prompt_logs`.

**Tech Stack:** PHP 8.2+, Laravel 12, Livewire/Flux, PHPUnit 11, `openai-php/client` (vorhanden), `anthropic-ai/sdk` (neu), Laravel `Http`-Client für Gemini.

**Spec:** `docs/superpowers/specs/2026-09-20-generische-turn-pipeline-design.md`. Dieser Plan deckt die Teilprojekte 1 bis 3 der Spec ab (LLM-Schicht, Durchstich, Memory und Think samt Aufräumarbeiten). Persona-Schicht, die drei übrigen Pipelines, Auswertungsskript und `RunExperiment` bekommen eigene Pläne.

## Global Constraints

- Arbeitsbranch ist `Studie-JanNiclas-Loosen`. Nicht auf `main` arbeiten, nicht pushen.
- Die Legacy-Pipeline unter `app/Services/PromptingPipeline` wird bis Task 19 nicht verändert und nicht erweitert. Danach wird sie gelöscht.
- Keine ausgleichende Logik in Selektoren. Gleichstände werden zufällig aufgelöst, nie zugunsten seltener Sprecher.
- Längen werden in Wörtern und Zeichen gemessen, nie in Modell-Tokens. Gezählt werden nur öffentliche Beiträge.
- Alle Prompts sind Blade-Views unter `resources/views/prompts/`. Kein Prompt-Text in PHP. Ausgabe-Marker stehen einmal als Konstante in der Stage und werden als Variable in den View gereicht.
- Provider-Adapter kennen keine Views; der System-Prompt kommt im `LlmRequest`.
- Teilnehmer werden in Prompts nur über Tokens `E{id}` und `U{id}` referenziert, aufgelöst über `Project::contributorByPromptId()`. Nie über Namen.
- Keine serverseitigen Refusal-Fallbacks bei Anthropic. Eine Verweigerung ist ein Fehlschlag (`LlmException` mit `kind = 'refusal'`).
- Keine neuen Tabellen, keine Umbenennungen. Schemaänderungen erfolgen in den bestehenden `create_*`-Migrationen; die Tests laufen auf In-Memory-SQLite mit `RefreshDatabase`.
- Tests rufen nie eine echte API auf. Pipeline-Tests nutzen `Tests\Fakes\FakeLlmClient`.
- Prompts, UI-Texte und Fachbegriffe sind deutsch; Code-Bezeichner englisch. Deutsche Texte mit korrekten Umlauten.
- Vor jedem Commit: `vendor/bin/pint --dirty` und die im Task genannten Tests. Nach jedem Task den Skill `clean-code-review` auf die geänderten Dateien anwenden (globale Nutzerregel) und Befunde ab „Major" beheben.
- Commit-Nachrichten englisch, im Stil des Repos, und enden mit der Zeile
  `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Test-Baseline vor Task 0: 5 bekannte Fehlschläge und 92 PHP-8.5-Deprecations. Nach Task 0 bleibt genau ein bekannter Fehlschlag (`ProjectExportTest`), er wird in Task 19 behoben. Kein Task darf weitere Fehlschläge hinterlassen.

## Dateiübersicht

Neu:

```
config/llm.php
app/Llm/ModelConfig.php, LlmRequest.php, LlmResponse.php, LlmException.php
app/Llm/LlmClient.php, LlmFactory.php, LoggingLlmClient.php
app/Llm/Providers/CompletesConcurrently.php
app/Llm/Providers/OpenAiClient.php, AnthropicClient.php, GeminiClient.php

app/Discussion/TurnRunner.php, TurnPayload.php, TurnResult.php
app/Discussion/MissingPayloadSlot.php, GenerationLoop.php
app/Discussion/Values/Thought.php, Selection.php, Contribution.php
app/Discussion/Pipelines/TurnPipeline.php, PipelineRegistry.php, UnknownPipeline.php
app/Discussion/Pipelines/RoundRobinPipeline.php
app/Discussion/Stages/SelectSpeaker.php, ThinkAsSpeaker.php, Speak.php
app/Discussion/Stages/PersistMessage.php, Summarize.php
app/Discussion/Selectors/SpeakerSelector.php, RoundRobinSelector.php
app/Discussion/Memory/Memory.php, MemoryView.php
app/Discussion/Support/PromptRenderer.php, Thinking.php, TextLength.php, ReadingPause.php

resources/views/prompts/think/speaker.blade.php
resources/views/prompts/speak.blade.php
resources/views/prompts/summarize.blade.php
resources/views/prompts/partials/memory.blade.php
resources/views/prompts/partials/participants.blade.php
lang/de/pipelines.php, lang/en/pipelines.php

tests/Fakes/FakeLlmClient.php, FakeLlmFactory.php
tests/Fixtures/Pipelines/DummyPipeline.php, NotAPipeline.php
tests/Unit/Llm/*, tests/Unit/Discussion/*, tests/Feature/Discussion/*
```

Geändert: `config/database.php`, `config/discussion.php`, `composer.json`, die Migrationen für `projects`, `project_contributors`, `job_logs`, `prompt_logs`, `messages`; `app/Models/Project.php`, `Expert.php`, `Message.php`, `Summary.php`, `JobLog.php`, `PromptLog.php`; `app/Jobs/MessageGenerator.php`; `app/Livewire/Projects/ControlChat.php`, `CreateProject.php`, `EditProject.php` samt Views; `app/Services/ProjectTransfer/*`; `app/Console/Commands/BuildSuite.php`; `app/Providers/AppServiceProvider.php`; `database/factories/ProjectFactory.php`; `CLAUDE.md`, `docs/pipeline.puml`.

Gelöscht: `app/Jobs/Dependencies/ProjectJob.php` (Task 17); in Task 19 `app/Services/PromptingPipeline/`, `app/Services/Clients/OpenAIClient.php`, `config/apis.php`, `resources/views/prompts/agent/`, `resources/views/prompts/moderator/`, `resources/views/prompts/shorten-chat.blade.php` und die zugehörigen Tests.

---

## Teil A: Baseline und LLM-Schicht

### Task 0: Test-Baseline lesbar machen

Die Suite meldet 92 „deprecated", weil `config/database.php` unter PHP 8.5 eine veraltete PDO-Konstante nutzt. Vier Starter-Kit-Tests prüfen Routen, die es nicht mehr gibt. Beides verdeckt echte Fehler.

**Files:**
- Modify: `config/database.php:61` und `config/database.php:81`
- Modify: `tests/Feature/DashboardTest.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`
- Delete: `tests/Feature/ExampleTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: eine Suite mit genau einem bekannten Fehlschlag (`ProjectExportTest > owner can export json`)

- [ ] **Step 1: Baseline festhalten**

Run: `php artisan test 2>&1 | tail -3`
Expected: `Tests:    92 deprecated, 5 failed, 1 passed`

- [ ] **Step 2: PDO-Konstante versionssicher machen**

In `config/database.php` steht zweimal (Verbindungen `mysql` und `mariadb`):

```php
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
```

Beide Vorkommen ersetzen durch:

```php
                (PHP_VERSION_ID >= 80400 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
```

- [ ] **Step 3: Prüfen, dass die Routen wirklich fehlen**

Run: `php artisan route:list 2>/dev/null | grep -iE "register|dashboard"`
Expected: nur die Zeile `GET|HEAD  / ... dashboard › routes/web.php:14`. Es gibt keine Route `/register` und keine Route `/dashboard`.

- [ ] **Step 4: Veraltete Tests anpassen**

`tests/Feature/ExampleTest.php` löschen.

`tests/Feature/DashboardTest.php` vollständig ersetzen durch:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_users_are_sent_to_project_creation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('project.new'));
    }
}
```

In `tests/Feature/Auth/RegistrationTest.php` die Methode `test_registration_screen_can_be_rendered` samt Leerzeile entfernen. `test_new_users_can_register` bleibt.

- [ ] **Step 5: Suite prüfen**

Run: `php artisan test 2>&1 | tail -3`
Expected: `1 failed`, kein „deprecated" mehr. Der verbleibende Fehlschlag ist `ProjectExportTest > owner can export json`.

- [ ] **Step 6: Commit**

```bash
git add config/database.php tests/Feature/DashboardTest.php tests/Feature/Auth/RegistrationTest.php
git rm tests/Feature/ExampleTest.php
git commit -m "Make the test baseline readable" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 1: Wertobjekte und Modell-Konfiguration

**Files:**
- Create: `config/llm.php`
- Create: `app/Llm/ModelConfig.php`, `app/Llm/LlmRequest.php`, `app/Llm/LlmResponse.php`, `app/Llm/LlmException.php`
- Test: `tests/Unit/Llm/ModelConfigTest.php`

**Interfaces:**
- Consumes: nichts
- Produces:
  - `ModelConfig::fromConfig(string $key): ModelConfig` mit den Feldern `key`, `label`, `provider`, `model`, `maxOutputTokens`, `temperature` (`?float`), `reasoningEffort` (`?string`) und `toArray(): array`
  - `new LlmRequest(string $system, string $prompt, string $purpose, ?int $jobLogId = null, ?int $expertId = null)`; `purpose` ist einer von `think`, `select`, `speak`, `summarize`
  - `new LlmResponse(string $text, string $reasoning, string $model, string $checkpoint, int $tokensIn, int $tokensOut, int $tokensReasoning, int $latencyMs, string $finishReason)`
  - `new LlmException(string $message, string $kind = 'api', ?Throwable $previous = null)`; `kind` ist einer von `api`, `refusal`, `empty`, `parse`

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Llm/ModelConfigTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\ModelConfig;
use InvalidArgumentException;
use Tests\TestCase;

class ModelConfigTest extends TestCase
{
    public function test_builds_from_config(): void
    {
        config(['llm.models.demo' => [
            'label' => 'Demo',
            'provider' => 'openai',
            'model' => 'gpt-x',
            'max_output_tokens' => 1234,
            'temperature' => null,
            'reasoning_effort' => 'low',
        ]]);

        $config = ModelConfig::fromConfig('demo');

        $this->assertSame('demo', $config->key);
        $this->assertSame('openai', $config->provider);
        $this->assertSame('gpt-x', $config->model);
        $this->assertSame(1234, $config->maxOutputTokens);
        $this->assertNull($config->temperature);
        $this->assertSame('low', $config->reasoningEffort);
        $this->assertSame('gpt-x', $config->toArray()['model']);
    }

    public function test_unknown_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModelConfig::fromConfig('does-not-exist');
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ModelConfigTest`
Expected: FAIL mit `Class "App\Llm\ModelConfig" not found`

- [ ] **Step 3: Konfiguration anlegen**

`config/llm.php`:

```php
<?php

use App\Llm\Providers\AnthropicClient;
use App\Llm\Providers\GeminiClient;
use App\Llm\Providers\OpenAiClient;

return [

    // Schlüssel des Modells, das neue Projekte vorausgewählt bekommen.
    'default' => env('LLM_DEFAULT_MODEL', 'openai'),

    /*
    | Wählbare Modelle. Ein Projekt nutzt genau eines davon für alle Aufrufe
    | (Think, Selector, Speak, Summarize). temperature = null heißt: Parameter
    | nicht senden. Aktuelle Anthropic-Modelle und OpenAI-Reasoning-Modelle
    | lehnen ihn ab. reasoning_effort = null heißt: Anbieter-Default.
    */
    'models' => [
        'openai' => [
            'label' => 'OpenAI',
            'provider' => 'openai',
            'model' => env('OPENAI_MODEL', 'gpt-5'),
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'provider' => 'anthropic',
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('ANTHROPIC_REASONING_EFFORT', 'low'),
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'provider' => 'gemini',
            'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => null,
        ],
    ],

    // Ein neuer Anbieter ist eine neue Adapter-Klasse plus eine Zeile hier.
    'providers' => [
        'openai' => OpenAiClient::class,
        'anthropic' => AnthropicClient::class,
        'gemini' => GeminiClient::class,
    ],

    'keys' => [
        'openai' => env('OPENAI_API_KEY'),
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
    ],
];
```

- [ ] **Step 4: Wertobjekte anlegen**

`app/Llm/ModelConfig.php`:

```php
<?php

namespace App\Llm;

use InvalidArgumentException;

final readonly class ModelConfig
{
    public function __construct(
        public string $key,
        public string $label,
        public string $provider,
        public string $model,
        public int $maxOutputTokens,
        public ?float $temperature = null,
        public ?string $reasoningEffort = null,
    ) {}

    public static function fromConfig(string $key): self
    {
        $entry = config("llm.models.{$key}");

        if (! is_array($entry)) {
            throw new InvalidArgumentException("Unknown LLM model key [{$key}]. Check config/llm.php.");
        }

        return new self(
            key: $key,
            label: $entry['label'] ?? $key,
            provider: $entry['provider'],
            model: $entry['model'],
            maxOutputTokens: (int) ($entry['max_output_tokens'] ?? 8000),
            temperature: isset($entry['temperature']) ? (float) $entry['temperature'] : null,
            reasoningEffort: $entry['reasoning_effort'] ?? null,
        );
    }

    /** Snapshot for prompt_logs.config and projects.run_config. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'provider' => $this->provider,
            'model' => $this->model,
            'max_output_tokens' => $this->maxOutputTokens,
            'temperature' => $this->temperature,
            'reasoning_effort' => $this->reasoningEffort,
        ];
    }
}
```

`app/Llm/LlmRequest.php`:

```php
<?php

namespace App\Llm;

final readonly class LlmRequest
{
    public const PURPOSE_THINK = 'think';
    public const PURPOSE_SELECT = 'select';
    public const PURPOSE_SPEAK = 'speak';
    public const PURPOSE_SUMMARIZE = 'summarize';

    public function __construct(
        public string $system,
        public string $prompt,
        public string $purpose,
        public ?int $jobLogId = null,
        public ?int $expertId = null,
    ) {}
}
```

`app/Llm/LlmResponse.php`:

```php
<?php

namespace App\Llm;

final readonly class LlmResponse
{
    /**
     * @param  string  $text  visible answer, never contains reasoning
     * @param  string  $reasoning  reasoning summary if the provider returns one, else ''
     * @param  string  $model  the model id we asked for
     * @param  string  $checkpoint  the model id the provider reports back
     */
    public function __construct(
        public string $text,
        public string $reasoning,
        public string $model,
        public string $checkpoint,
        public int $tokensIn,
        public int $tokensOut,
        public int $tokensReasoning,
        public int $latencyMs,
        public string $finishReason,
    ) {}
}
```

`app/Llm/LlmException.php`:

```php
<?php

namespace App\Llm;

use RuntimeException;
use Throwable;

class LlmException extends RuntimeException
{
    public const KIND_API = 'api';
    public const KIND_REFUSAL = 'refusal';
    public const KIND_EMPTY = 'empty';
    public const KIND_PARSE = 'parse';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_API,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=ModelConfigTest`
Expected: PASS, 2 Tests

- [ ] **Step 6: Commit**

```bash
git add config/llm.php app/Llm tests/Unit/Llm/ModelConfigTest.php
git commit -m "Add LLM value objects and model configuration" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: `LlmClient`-Interface und Test-Fake

**Files:**
- Create: `app/Llm/LlmClient.php`
- Create: `tests/Fakes/FakeLlmClient.php`
- Test: `tests/Unit/Llm/FakeLlmClientTest.php`

**Interfaces:**
- Consumes: `LlmRequest`, `LlmResponse`, `LlmException`, `ModelConfig` aus Task 1
- Produces:
  - `interface LlmClient { complete(LlmRequest): LlmResponse; completeMany(array $requests): array; config(): ModelConfig; }`. `completeMany` erhält `array<array-key, LlmRequest>` und gibt `array<array-key, LlmResponse>` mit denselben Schlüsseln zurück.
  - `FakeLlmClient::push(string $purpose, string ...$texts): static`, `failOn(string $purpose, string $message = 'boom', string $kind = 'api'): static`, öffentliche Liste `$requests`, `requestsFor(string $purpose): array`

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Llm/FakeLlmClientTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class FakeLlmClientTest extends TestCase
{
    public function test_returns_queued_texts_per_purpose_and_repeats_the_last(): void
    {
        $fake = (new FakeLlmClient)->push('speak', 'eins', 'zwei');

        $request = new LlmRequest('sys', 'prompt', 'speak');

        $this->assertSame('eins', $fake->complete($request)->text);
        $this->assertSame('zwei', $fake->complete($request)->text);
        $this->assertSame('zwei', $fake->complete($request)->text);
        $this->assertCount(3, $fake->requestsFor('speak'));
    }

    public function test_complete_many_preserves_keys(): void
    {
        $fake = (new FakeLlmClient)->push('think', 'a', 'b');

        $responses = $fake->completeMany([
            7 => new LlmRequest('sys', 'p1', 'think', expertId: 7),
            9 => new LlmRequest('sys', 'p2', 'think', expertId: 9),
        ]);

        $this->assertSame([7, 9], array_keys($responses));
        $this->assertSame('a', $responses[7]->text);
        $this->assertSame('b', $responses[9]->text);
    }

    public function test_can_fail_on_a_purpose(): void
    {
        $fake = (new FakeLlmClient)->failOn('speak', 'kaputt', LlmException::KIND_REFUSAL);

        try {
            $fake->complete(new LlmRequest('sys', 'prompt', 'speak'));
            $this->fail('expected an LlmException');
        } catch (LlmException $e) {
            $this->assertSame('kaputt', $e->getMessage());
            $this->assertSame(LlmException::KIND_REFUSAL, $e->kind);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=FakeLlmClientTest`
Expected: FAIL mit `Class "Tests\Fakes\FakeLlmClient" not found`

- [ ] **Step 3: Interface anlegen**

`app/Llm/LlmClient.php`:

```php
<?php

namespace App\Llm;

interface LlmClient
{
    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Run several requests, in parallel where the implementation can.
     *
     * @param  array<array-key, LlmRequest>  $requests
     * @return array<array-key, LlmResponse> same keys as $requests
     *
     * @throws LlmException
     */
    public function completeMany(array $requests): array;

    public function config(): ModelConfig;
}
```

- [ ] **Step 4: Fake anlegen**

`tests/Fakes/FakeLlmClient.php`:

```php
<?php

namespace Tests\Fakes;

use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;

class FakeLlmClient implements LlmClient
{
    /** @var LlmRequest[] */
    public array $requests = [];

    /** @var array<string, string[]> */
    private array $queues = [];

    /** @var array<string, LlmException> */
    private array $failures = [];

    public function push(string $purpose, string ...$texts): static
    {
        $this->queues[$purpose] = [...($this->queues[$purpose] ?? []), ...$texts];

        return $this;
    }

    public function failOn(string $purpose, string $message = 'boom', string $kind = LlmException::KIND_API): static
    {
        $this->failures[$purpose] = new LlmException($message, $kind);

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        if (isset($this->failures[$request->purpose])) {
            throw $this->failures[$request->purpose];
        }

        $queue = $this->queues[$request->purpose] ?? ['fake answer'];
        $text = count($queue) > 1 ? array_shift($queue) : $queue[0];
        $this->queues[$request->purpose] = $queue;

        return new LlmResponse(
            text: $text,
            reasoning: '',
            model: 'fake-model',
            checkpoint: 'fake-model-001',
            tokensIn: 10,
            tokensOut: 5,
            tokensReasoning: 0,
            latencyMs: 1,
            finishReason: 'completed',
        );
    }

    public function completeMany(array $requests): array
    {
        return array_map(fn (LlmRequest $request) => $this->complete($request), $requests);
    }

    public function config(): ModelConfig
    {
        return new ModelConfig('fake', 'Fake', 'fake', 'fake-model', 1000);
    }

    /** @return LlmRequest[] */
    public function requestsFor(string $purpose): array
    {
        return array_values(array_filter($this->requests, fn (LlmRequest $r) => $r->purpose === $purpose));
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=FakeLlmClientTest`
Expected: PASS, 3 Tests

- [ ] **Step 6: Commit**

```bash
git add app/Llm/LlmClient.php tests/Fakes/FakeLlmClient.php tests/Unit/Llm/FakeLlmClientTest.php
git commit -m "Add the LlmClient interface and a test fake" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Nebenläufigkeit und OpenAI-Adapter

**Files:**
- Create: `app/Llm/Providers/CompletesConcurrently.php`
- Create: `app/Llm/Providers/OpenAiClient.php`
- Test: `tests/Unit/Llm/OpenAiClientTest.php`

**Interfaces:**
- Consumes: `LlmClient`, Wertobjekte aus Task 1; `LlmFactory::adapter(string $key): LlmClient` aus Task 6 (nur zur Laufzeit im Trait, in diesem Task nicht getestet)
- Produces:
  - Trait `CompletesConcurrently` mit `completeMany(array $requests): array`
  - `OpenAiClient::make(ModelConfig $config): static`, `new OpenAiClient(ModelConfig $config, \OpenAI\Contracts\ClientContract $client)`, öffentliche Methoden `buildParams(LlmRequest): array` und `mapResponse(\OpenAI\Responses\Responses\CreateResponse $response, int $latencyMs): LlmResponse`

Jeder Adapter hat dieselbe Form: `buildParams` und `mapResponse` sind reine Funktionen und ohne Netzwerk testbar, `complete` ist der dünne Aufruf dazwischen.

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Llm/OpenAiClientTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\ModelConfig;
use App\Llm\Providers\OpenAiClient;
use Exception;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    private function config(?float $temperature = null, ?string $effort = null): ModelConfig
    {
        return new ModelConfig('openai', 'OpenAI', 'openai', 'gpt-x', 4000, $temperature, $effort);
    }

    public function test_build_params_omits_options_that_are_not_set(): void
    {
        $client = new OpenAiClient($this->config(), new ClientFake);

        $params = $client->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame([
            'model' => 'gpt-x',
            'instructions' => 'SYSTEM',
            'input' => 'PROMPT',
            'max_output_tokens' => 4000,
        ], $params);
    }

    public function test_build_params_sends_temperature_and_reasoning_when_set(): void
    {
        $client = new OpenAiClient($this->config(0.7, 'low'), new ClientFake);

        $params = $client->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame(0.7, $params['temperature']);
        $this->assertSame(['effort' => 'low', 'summary' => 'auto'], $params['reasoning']);
    }

    public function test_maps_the_sdk_response(): void
    {
        $sdk = CreateResponse::fake();
        $client = new OpenAiClient($this->config(), new ClientFake);

        $response = $client->mapResponse($sdk, 120);

        $this->assertSame($sdk->outputText, $response->text);
        $this->assertSame('gpt-x', $response->model);
        $this->assertSame($sdk->model, $response->checkpoint);
        $this->assertSame($sdk->usage->inputTokens, $response->tokensIn);
        $this->assertSame($sdk->usage->outputTokens, $response->tokensOut);
        $this->assertSame($sdk->usage->outputTokensDetails->reasoningTokens, $response->tokensReasoning);
        $this->assertSame(120, $response->latencyMs);
        $this->assertSame($sdk->status, $response->finishReason);
    }

    public function test_complete_calls_the_responses_api(): void
    {
        $sdk = new ClientFake([CreateResponse::fake()]);
        $client = new OpenAiClient($this->config(), $sdk);

        $response = $client->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertNotSame('', $response->text);
        $sdk->assertSent(Responses::class, fn (string $method, array $parameters) => $method === 'create'
            && $parameters['model'] === 'gpt-x'
            && $parameters['input'] === 'PROMPT');
    }

    public function test_api_errors_become_llm_exceptions(): void
    {
        $client = new OpenAiClient($this->config(), new ClientFake([new Exception('service down')]));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('service down');

        $client->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=OpenAiClientTest`
Expected: FAIL mit `Class "App\Llm\Providers\OpenAiClient" not found`

- [ ] **Step 3: Trait für parallele Aufrufe anlegen**

`app/Llm/Providers/CompletesConcurrently.php`:

```php
<?php

namespace App\Llm\Providers;

use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use Illuminate\Support\Facades\Concurrency;

/**
 * Runs several requests at once through Laravel's Concurrency facade. Each task
 * rebuilds its adapter from the model key inside the child process, so nothing
 * but plain value objects crosses the process boundary.
 */
trait CompletesConcurrently
{
    public function completeMany(array $requests): array
    {
        if (count($requests) <= 1) {
            return array_map(fn (LlmRequest $request) => $this->complete($request), $requests);
        }

        $modelKey = $this->config()->key;
        $keys = array_keys($requests);

        $tasks = [];
        foreach ($requests as $request) {
            $tasks[] = static fn () => app(LlmFactory::class)->adapter($modelKey)->complete($request);
        }

        // Concurrency::run returns a list; restore the caller's keys.
        return array_combine($keys, array_values(Concurrency::run($tasks)));
    }
}
```

- [ ] **Step 4: Adapter anlegen**

`app/Llm/Providers/OpenAiClient.php`:

```php
<?php

namespace App\Llm\Providers;

use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Responses\Output\OutputReasoning;
use Throwable;

final class OpenAiClient implements LlmClient
{
    use CompletesConcurrently;

    public function __construct(
        private readonly ModelConfig $config,
        private readonly ClientContract $client,
    ) {}

    public static function make(ModelConfig $config): static
    {
        return new self($config, OpenAI::client((string) config('llm.keys.openai')));
    }

    public function config(): ModelConfig
    {
        return $this->config;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $start = microtime(true);

        try {
            $response = $this->client->responses()->create($this->buildParams($request));
        } catch (Throwable $e) {
            throw new LlmException($e->getMessage(), LlmException::KIND_API, $e);
        }

        return $this->mapResponse($response, (int) round((microtime(true) - $start) * 1000));
    }

    public function buildParams(LlmRequest $request): array
    {
        $params = [
            'model' => $this->config->model,
            'instructions' => $request->system,
            'input' => $request->prompt,
            'max_output_tokens' => $this->config->maxOutputTokens,
        ];

        if ($this->config->temperature !== null) {
            $params['temperature'] = $this->config->temperature;
        }

        if ($this->config->reasoningEffort !== null) {
            $params['reasoning'] = ['effort' => $this->config->reasoningEffort, 'summary' => 'auto'];
        }

        return $params;
    }

    public function mapResponse(CreateResponse $response, int $latencyMs): LlmResponse
    {
        $text = trim((string) $response->outputText);

        if ($text === '') {
            throw new LlmException("OpenAI returned no text (status: {$response->status}).", LlmException::KIND_EMPTY);
        }

        return new LlmResponse(
            text: $text,
            reasoning: $this->reasoningSummary($response),
            model: $this->config->model,
            checkpoint: $response->model,
            tokensIn: $response->usage?->inputTokens ?? 0,
            tokensOut: $response->usage?->outputTokens ?? 0,
            tokensReasoning: $response->usage?->outputTokensDetails->reasoningTokens ?? 0,
            latencyMs: $latencyMs,
            finishReason: $response->status,
        );
    }

    /** OpenAI never returns raw reasoning, only optional summaries. */
    private function reasoningSummary(CreateResponse $response): string
    {
        $parts = [];

        foreach ($response->output as $item) {
            if ($item instanceof OutputReasoning) {
                foreach ($item->summary as $summary) {
                    $parts[] = $summary->text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=OpenAiClientTest`
Expected: PASS, 5 Tests. Falls `assertSent` eine andere Callback-Signatur verlangt, in `vendor/openai-php/client/src/Testing/ClientFake.php` nachsehen und den Test an die dortige Signatur anpassen, nicht den Adapter.

- [ ] **Step 6: Commit**

```bash
git add app/Llm/Providers tests/Unit/Llm/OpenAiClientTest.php
git commit -m "Add the OpenAI adapter behind LlmClient" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Anthropic-Adapter

Der Adapter nutzt das offizielle PHP-SDK. Aktuelle Claude-Modelle geben nie das rohe Reasoning zurück; mit `display: summarized` kommt eine Zusammenfassung. `budgetTokens` und `temperature` werden von aktuellen Modellen mit HTTP 400 abgelehnt, deshalb sendet der Adapter nur, was in der Config gesetzt ist.

**Files:**
- Modify: `composer.json` (über `composer require`)
- Create: `app/Llm/Providers/AnthropicClient.php`
- Test: `tests/Unit/Llm/AnthropicClientTest.php`

**Interfaces:**
- Consumes: `LlmClient`, Wertobjekte, Trait `CompletesConcurrently`
- Produces: `AnthropicClient::make(ModelConfig $config): static`, `new AnthropicClient(ModelConfig $config, \Anthropic\Client $client)`, `buildParams(LlmRequest): array` (Schlüssel sind die camelCase-Named-Arguments des SDK), `mapResponse(object $message, int $latencyMs): LlmResponse`

- [ ] **Step 1: SDK installieren**

Run: `composer require "anthropic-ai/sdk"`
Expected: Paket wird installiert, `composer.json` und `composer.lock` ändern sich.

- [ ] **Step 2: Signatur im installierten SDK prüfen**

Run: `grep -rn "public function create" vendor/anthropic-ai/sdk/src --include=*.php | grep -i messages | head`

Die gefundene Datei öffnen und prüfen, dass `create()` die Named Arguments `model`, `maxTokens`, `messages`, `system`, `thinking`, `outputConfig` und `temperature` kennt. Heißt eines anders, den Namen in `buildParams` und im Test anpassen. Nicht raten: Der Name aus dem SDK gilt.

- [ ] **Step 3: Failing test schreiben**

`tests/Unit/Llm/AnthropicClientTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\ModelConfig;
use App\Llm\Providers\AnthropicClient;
use Anthropic\Client;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    private function adapter(?float $temperature = null, ?string $effort = null): AnthropicClient
    {
        return new AnthropicClient(
            new ModelConfig('anthropic', 'Anthropic', 'anthropic', 'claude-opus-5', 4000, $temperature, $effort),
            new Client(apiKey: 'test-key'),
        );
    }

    private function message(array $content, string $stopReason = 'end_turn'): object
    {
        return (object) [
            'content' => array_map(fn (array $block) => (object) $block, $content),
            'model' => 'claude-opus-5',
            'stopReason' => $stopReason,
            'usage' => (object) ['inputTokens' => 100, 'outputTokens' => 40],
        ];
    }

    public function test_build_params_without_optional_settings(): void
    {
        $params = $this->adapter()->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame([
            'model' => 'claude-opus-5',
            'maxTokens' => 4000,
            'system' => 'SYSTEM',
            'messages' => [['role' => 'user', 'content' => 'PROMPT']],
        ], $params);
    }

    public function test_build_params_with_effort_asks_for_summarized_adaptive_thinking(): void
    {
        $params = $this->adapter(effort: 'low')->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $params['thinking']);
        $this->assertSame(['effort' => 'low'], $params['outputConfig']);
        $this->assertArrayNotHasKey('temperature', $params);
    }

    public function test_maps_text_and_thinking_blocks_separately(): void
    {
        $message = $this->message([
            ['type' => 'thinking', 'thinking' => 'kurze Überlegung'],
            ['type' => 'text', 'text' => 'Sichtbarer Beitrag.'],
        ]);

        $response = $this->adapter()->mapResponse($message, 250);

        $this->assertSame('Sichtbarer Beitrag.', $response->text);
        $this->assertSame('kurze Überlegung', $response->reasoning);
        $this->assertSame('claude-opus-5', $response->checkpoint);
        $this->assertSame(100, $response->tokensIn);
        $this->assertSame(40, $response->tokensOut);
        $this->assertSame(0, $response->tokensReasoning);
        $this->assertSame('end_turn', $response->finishReason);
    }

    public function test_refusal_is_a_failure_not_a_fallback(): void
    {
        $this->expectException(LlmException::class);

        try {
            $this->adapter()->mapResponse($this->message([], 'refusal'), 10);
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_REFUSAL, $e->kind);
            throw $e;
        }
    }

    public function test_empty_text_is_a_failure(): void
    {
        $this->expectException(LlmException::class);

        $this->adapter()->mapResponse($this->message([['type' => 'thinking', 'thinking' => 'nur gedacht']]), 10);
    }
}
```

- [ ] **Step 4: Test laufen lassen**

Run: `php artisan test --filter=AnthropicClientTest`
Expected: FAIL mit `Class "App\Llm\Providers\AnthropicClient" not found`

- [ ] **Step 5: Adapter anlegen**

`app/Llm/Providers/AnthropicClient.php`:

```php
<?php

namespace App\Llm\Providers;

use Anthropic\Client;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use BackedEnum;
use Throwable;

final class AnthropicClient implements LlmClient
{
    use CompletesConcurrently;

    public function __construct(
        private readonly ModelConfig $config,
        private readonly Client $client,
    ) {}

    public static function make(ModelConfig $config): static
    {
        return new self($config, new Client(apiKey: (string) config('llm.keys.anthropic')));
    }

    public function config(): ModelConfig
    {
        return $this->config;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $start = microtime(true);

        try {
            $message = $this->client->messages->create(...$this->buildParams($request));
        } catch (Throwable $e) {
            throw new LlmException($e->getMessage(), LlmException::KIND_API, $e);
        }

        return $this->mapResponse($message, (int) round((microtime(true) - $start) * 1000));
    }

    /**
     * Keys are the SDK's camelCase named arguments. No `fallbacks`: a silent
     * switch to another model would corrupt the study's model factor.
     */
    public function buildParams(LlmRequest $request): array
    {
        $params = [
            'model' => $this->config->model,
            'maxTokens' => $this->config->maxOutputTokens,
            'system' => $request->system,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];

        if ($this->config->temperature !== null) {
            $params['temperature'] = $this->config->temperature;
        }

        if ($this->config->reasoningEffort !== null) {
            $params['thinking'] = ['type' => 'adaptive', 'display' => 'summarized'];
            $params['outputConfig'] = ['effort' => $this->config->reasoningEffort];
        }

        return $params;
    }

    public function mapResponse(object $message, int $latencyMs): LlmResponse
    {
        $stopReason = $this->scalar($message->stopReason);

        if ($stopReason === 'refusal') {
            throw new LlmException('Anthropic refused the request.', LlmException::KIND_REFUSAL);
        }

        $text = '';
        $reasoning = '';

        foreach ($message->content as $block) {
            $type = $this->scalar($block->type);

            if ($type === 'text') {
                $text .= $block->text;
            } elseif ($type === 'thinking') {
                $reasoning .= $block->thinking;
            }
        }

        if (trim($text) === '') {
            throw new LlmException("Anthropic returned no text (stop reason: {$stopReason}).", LlmException::KIND_EMPTY);
        }

        return new LlmResponse(
            text: trim($text),
            reasoning: trim($reasoning),
            model: $this->config->model,
            checkpoint: (string) $message->model,
            tokensIn: (int) $message->usage->inputTokens,
            tokensOut: (int) $message->usage->outputTokens,
            // Anthropic bills thinking inside output tokens and reports no separate count.
            tokensReasoning: 0,
            latencyMs: $latencyMs,
            finishReason: $stopReason,
        );
    }

    private function scalar(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
```

- [ ] **Step 6: Test laufen lassen**

Run: `php artisan test --filter=AnthropicClientTest`
Expected: PASS, 5 Tests

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock app/Llm/Providers/AnthropicClient.php tests/Unit/Llm/AnthropicClientTest.php
git commit -m "Add the Anthropic adapter behind LlmClient" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Gemini-Adapter

Für Gemini gibt es kein offizielles PHP-SDK, deshalb Laravels `Http`-Client gegen die REST-API.

**Files:**
- Create: `app/Llm/Providers/GeminiClient.php`
- Test: `tests/Unit/Llm/GeminiClientTest.php`

**Interfaces:**
- Consumes: `LlmClient`, Wertobjekte, Trait `CompletesConcurrently`
- Produces: `GeminiClient::make(ModelConfig $config): static`, `new GeminiClient(ModelConfig $config, string $apiKey)`, `buildParams(LlmRequest): array`, `mapResponse(array $body, int $latencyMs): LlmResponse`

- [ ] **Step 1: Feldnamen gegen die aktuelle Doku prüfen**

Die Seiten `https://ai.google.dev/api/generate-content` und `https://ai.google.dev/gemini-api/docs/thinking` abrufen und gegen diese Annahmen halten: Endpunkt `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`, Header `x-goog-api-key`, Request-Felder `systemInstruction.parts[].text`, `contents[].parts[].text`, `generationConfig.maxOutputTokens`, `generationConfig.temperature`, `generationConfig.thinkingConfig.includeThoughts`; Response-Felder `candidates[0].content.parts[]` mit `text` und optional `thought: true`, `candidates[0].finishReason`, `usageMetadata.promptTokenCount`, `candidatesTokenCount`, `thoughtsTokenCount`, `modelVersion`. Weicht ein Name ab, gilt die Doku; Test und Adapter entsprechend anpassen.

- [ ] **Step 2: Failing test schreiben**

`tests/Unit/Llm/GeminiClientTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\ModelConfig;
use App\Llm\Providers\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private function adapter(?float $temperature = null): GeminiClient
    {
        return new GeminiClient(
            new ModelConfig('gemini', 'Gemini', 'gemini', 'gemini-x', 4000, $temperature),
            'test-key',
        );
    }

    private function body(array $parts, string $finishReason = 'STOP'): array
    {
        return [
            'candidates' => [['content' => ['parts' => $parts], 'finishReason' => $finishReason]],
            'usageMetadata' => ['promptTokenCount' => 90, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 12],
            'modelVersion' => 'gemini-x-001',
        ];
    }

    public function test_build_params(): void
    {
        $params = $this->adapter(0.5)->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame('SYSTEM', $params['systemInstruction']['parts'][0]['text']);
        $this->assertSame('PROMPT', $params['contents'][0]['parts'][0]['text']);
        $this->assertSame(4000, $params['generationConfig']['maxOutputTokens']);
        $this->assertSame(0.5, $params['generationConfig']['temperature']);
        $this->assertTrue($params['generationConfig']['thinkingConfig']['includeThoughts']);
    }

    public function test_temperature_is_omitted_when_not_set(): void
    {
        $params = $this->adapter()->buildParams(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertArrayNotHasKey('temperature', $params['generationConfig']);
    }

    public function test_maps_answer_and_thought_parts_separately(): void
    {
        $response = $this->adapter()->mapResponse($this->body([
            ['text' => 'innere Überlegung', 'thought' => true],
            ['text' => 'Sichtbarer Beitrag.'],
        ]), 300);

        $this->assertSame('Sichtbarer Beitrag.', $response->text);
        $this->assertSame('innere Überlegung', $response->reasoning);
        $this->assertSame('gemini-x-001', $response->checkpoint);
        $this->assertSame(90, $response->tokensIn);
        $this->assertSame(30, $response->tokensOut);
        $this->assertSame(12, $response->tokensReasoning);
        $this->assertSame('STOP', $response->finishReason);
    }

    public function test_complete_posts_to_the_model_endpoint(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->body([['text' => 'Hallo.']]))]);

        $response = $this->adapter()->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));

        $this->assertSame('Hallo.', $response->text);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/models/gemini-x:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function test_http_errors_become_llm_exceptions(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $this->expectException(LlmException::class);

        $this->adapter()->complete(new LlmRequest('SYSTEM', 'PROMPT', 'speak'));
    }

    public function test_missing_text_is_a_failure(): void
    {
        $this->expectException(LlmException::class);

        $this->adapter()->mapResponse($this->body([], 'SAFETY'), 10);
    }
}
```

- [ ] **Step 3: Test laufen lassen**

Run: `php artisan test --filter=GeminiClientTest`
Expected: FAIL mit `Class "App\Llm\Providers\GeminiClient" not found`

- [ ] **Step 4: Adapter anlegen**

`app/Llm/Providers/GeminiClient.php`:

```php
<?php

namespace App\Llm\Providers;

use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiClient implements LlmClient
{
    use CompletesConcurrently;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly ModelConfig $config,
        private readonly string $apiKey,
    ) {}

    public static function make(ModelConfig $config): static
    {
        return new self($config, (string) config('llm.keys.gemini'));
    }

    public function config(): ModelConfig
    {
        return $this->config;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $start = microtime(true);

        try {
            $body = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout(120)
                ->post(self::BASE_URL."/models/{$this->config->model}:generateContent", $this->buildParams($request))
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new LlmException($e->getMessage(), LlmException::KIND_API, $e);
        }

        return $this->mapResponse($body ?? [], (int) round((microtime(true) - $start) * 1000));
    }

    public function buildParams(LlmRequest $request): array
    {
        $generation = [
            'maxOutputTokens' => $this->config->maxOutputTokens,
            'thinkingConfig' => ['includeThoughts' => true],
        ];

        if ($this->config->temperature !== null) {
            $generation['temperature'] = $this->config->temperature;
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'generationConfig' => $generation,
        ];
    }

    public function mapResponse(array $body, int $latencyMs): LlmResponse
    {
        $candidate = $body['candidates'][0] ?? [];
        $finishReason = (string) ($candidate['finishReason'] ?? 'UNKNOWN');

        $text = '';
        $reasoning = '';

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (! empty($part['thought'])) {
                $reasoning .= $part['text'] ?? '';
            } else {
                $text .= $part['text'] ?? '';
            }
        }

        if (trim($text) === '') {
            throw new LlmException("Gemini returned no text (finish reason: {$finishReason}).", LlmException::KIND_EMPTY);
        }

        $usage = $body['usageMetadata'] ?? [];

        return new LlmResponse(
            text: trim($text),
            reasoning: trim($reasoning),
            model: $this->config->model,
            checkpoint: (string) ($body['modelVersion'] ?? $this->config->model),
            tokensIn: (int) ($usage['promptTokenCount'] ?? 0),
            tokensOut: (int) ($usage['candidatesTokenCount'] ?? 0),
            tokensReasoning: (int) ($usage['thoughtsTokenCount'] ?? 0),
            latencyMs: $latencyMs,
            finishReason: $finishReason,
        );
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=GeminiClientTest`
Expected: PASS, 6 Tests

- [ ] **Step 6: Commit**

```bash
git add app/Llm/Providers/GeminiClient.php tests/Unit/Llm/GeminiClientTest.php
git commit -m "Add the Gemini adapter behind LlmClient" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: `LlmFactory`, Logging-Decorator und Log-Spalten

Ersetzt das statische `OpenAIClient::bindJobLog()`: Die Job-Log-ID reist im `LlmRequest`, der Decorator schreibt jede Antwort und jeden Fehlschlag nach `prompt_logs`.

**Files:**
- Modify: `database/migrations/2026_04_21_000000_create_prompt_logs_table.php`
- Modify: `database/migrations/2025_05_30_132422_create_projects_table.php`
- Modify: `app/Models/PromptLog.php`, `app/Models/Project.php`, `database/factories/ProjectFactory.php`
- Create: `app/Llm/LlmFactory.php`, `app/Llm/LoggingLlmClient.php`
- Create: `tests/Fakes/FakeLlmFactory.php`
- Test: `tests/Unit/Llm/LlmFactoryTest.php`, `tests/Unit/Llm/LoggingLlmClientTest.php`, `tests/Unit/Llm/CompletesConcurrentlyTest.php`

**Interfaces:**
- Consumes: alles aus Task 1 bis 5
- Produces:
  - `LlmFactory::adapter(string $modelKey): LlmClient` (nackter Adapter), `LlmFactory::forProject(Project $project): LlmClient` (Adapter im Logging-Decorator), `LlmFactory::options(): array<string, string>` (Schlüssel → Label für Dropdowns)
  - `new LoggingLlmClient(LlmClient $inner)`
  - `new FakeLlmFactory(LlmClient $client)`; überschreibt nur `adapter()`, `forProject()` umhüllt den Fake also weiterhin mit dem Logging-Decorator
  - Spalte `projects.model` (string, Default `openai`), in `Project::$fillable`
  - neue `prompt_logs`-Spalten: `provider`, `checkpoint`, `config` (JSON), `purpose`, `expert_id`, `reasoning`, `tokens_in`, `tokens_out`, `tokens_reasoning`, `status` (`ok`|`failed`), `error`

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/Llm/LlmFactoryTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmFactory;
use App\Llm\LoggingLlmClient;
use App\Llm\Providers\AnthropicClient;
use App\Llm\Providers\GeminiClient;
use App\Llm\Providers\OpenAiClient;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LlmFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.keys' => ['openai' => 'k1', 'anthropic' => 'k2', 'gemini' => 'k3']]);
    }

    public function test_builds_the_adapter_for_each_provider(): void
    {
        $factory = new LlmFactory;

        $this->assertInstanceOf(OpenAiClient::class, $factory->adapter('openai'));
        $this->assertInstanceOf(AnthropicClient::class, $factory->adapter('anthropic'));
        $this->assertInstanceOf(GeminiClient::class, $factory->adapter('gemini'));
    }

    public function test_unknown_provider_throws(): void
    {
        config(['llm.models.odd' => ['provider' => 'nope', 'model' => 'x']]);

        $this->expectException(InvalidArgumentException::class);

        (new LlmFactory)->adapter('odd');
    }

    public function test_project_client_is_wrapped_in_the_logging_decorator(): void
    {
        $project = Project::factory()->create(['model' => 'gemini']);

        $client = (new LlmFactory)->forProject($project);

        $this->assertInstanceOf(LoggingLlmClient::class, $client);
        $this->assertSame('gemini', $client->config()->key);
    }

    public function test_lists_model_options_for_dropdowns(): void
    {
        $options = (new LlmFactory)->options();

        $this->assertSame('OpenAI', $options['openai']);
        $this->assertArrayHasKey('anthropic', $options);
        $this->assertArrayHasKey('gemini', $options);
    }
}
```

`tests/Unit/Llm/LoggingLlmClientTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LoggingLlmClient;
use App\Models\Expert;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

class LoggingLlmClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_a_successful_call(): void
    {
        $expert = Expert::factory()->create();
        $client = new LoggingLlmClient((new FakeLlmClient)->push('speak', 'Hallo Welt.'));

        $response = $client->complete(new LlmRequest('SYS', 'PROMPT', 'speak', expertId: $expert->id));

        $this->assertSame('Hallo Welt.', $response->text);

        $log = PromptLog::sole();
        $this->assertSame('ok', $log->status);
        $this->assertSame('speak', $log->purpose);
        $this->assertSame("speak:{$expert->id}", $log->label);
        $this->assertSame($expert->id, $log->expert_id);
        $this->assertSame('PROMPT', $log->prompt);
        $this->assertSame('Hallo Welt.', $log->response);
        $this->assertSame('fake', $log->provider);
        $this->assertSame('fake-model-001', $log->checkpoint);
        $this->assertSame('fake-model', $log->config['model']);
        $this->assertSame(10, $log->tokens_in);
    }

    public function test_logs_a_failed_call_and_rethrows(): void
    {
        $client = new LoggingLlmClient((new FakeLlmClient)->failOn('speak', 'verweigert', LlmException::KIND_REFUSAL));

        try {
            $client->complete(new LlmRequest('SYS', 'PROMPT', 'speak'));
            $this->fail('expected an LlmException');
        } catch (LlmException) {
            // expected
        }

        $log = PromptLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('refusal: verweigert', $log->error);
        $this->assertSame('', $log->response);
    }

    public function test_logs_every_call_of_a_batch(): void
    {
        [$first, $second] = Expert::factory()->count(2)->create()->all();
        $client = new LoggingLlmClient((new FakeLlmClient)->push('think', 'a', 'b'));

        $responses = $client->completeMany([
            $first->id => new LlmRequest('SYS', 'P1', 'think', expertId: $first->id),
            $second->id => new LlmRequest('SYS', 'P2', 'think', expertId: $second->id),
        ]);

        $this->assertSame([$first->id, $second->id], array_keys($responses));
        $this->assertSame(
            ["think:{$first->id}", "think:{$second->id}"],
            PromptLog::orderBy('id')->pluck('label')->all(),
        );
    }
}
```

`tests/Unit/Llm/CompletesConcurrentlyTest.php`:

```php
<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmClient;
use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use App\Llm\Providers\CompletesConcurrently;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class CompletesConcurrentlyTest extends TestCase
{
    public function test_runs_a_batch_through_the_factory_and_keeps_keys(): void
    {
        config(['concurrency.default' => 'sync']);

        $worker = (new FakeLlmClient)->push('think', 'eins', 'zwei');
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($worker));

        $adapter = new class implements LlmClient
        {
            use CompletesConcurrently;

            public function complete(LlmRequest $request): LlmResponse
            {
                throw new \LogicException('a batch must go through the factory');
            }

            public function config(): ModelConfig
            {
                return new ModelConfig('fake', 'Fake', 'fake', 'fake-model', 1000);
            }
        };

        $responses = $adapter->completeMany([
            11 => new LlmRequest('SYS', 'P1', 'think'),
            12 => new LlmRequest('SYS', 'P2', 'think'),
        ]);

        $this->assertSame([11, 12], array_keys($responses));
        $this->assertSame('eins', $responses[11]->text);
        $this->assertSame('zwei', $responses[12]->text);
    }
}
```

- [ ] **Step 2: Tests laufen lassen**

Run: `php artisan test --filter="LlmFactoryTest|LoggingLlmClientTest|CompletesConcurrentlyTest"`
Expected: FAIL mit `Class "App\Llm\LlmFactory" not found`

- [ ] **Step 3: Migrationen erweitern**

In `database/migrations/2026_04_21_000000_create_prompt_logs_table.php` nach der Zeile `$table->unsignedInteger('latency_ms')->nullable();` einfügen:

```php
            $table->string('provider')->nullable();
            $table->string('checkpoint')->nullable();
            $table->json('config')->nullable();
            $table->string('purpose')->nullable();
            $table->foreignId('expert_id')->nullable()->constrained('experts')->nullOnDelete();
            $table->longText('reasoning')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('tokens_reasoning')->nullable();
            $table->string('status')->default('ok');
            $table->text('error')->nullable();
```

In `database/migrations/2025_05_30_132422_create_projects_table.php` nach `$table->longText('description');` einfügen:

```php
            $table->string('model')->default('openai');
```

- [ ] **Step 4: Models anpassen**

`app/Models/PromptLog.php`, `$fillable` ersetzen und Casts ergänzen:

```php
    protected $fillable = [
        'job_log_id', 'label', 'model', 'prompt', 'response', 'latency_ms',
        'provider', 'checkpoint', 'config', 'purpose', 'expert_id', 'reasoning',
        'tokens_in', 'tokens_out', 'tokens_reasoning', 'status', 'error',
    ];

    protected $casts = ['config' => 'array'];
```

`app/Models/Project.php`, `$fillable` ersetzen:

```php
    protected $fillable = ['title', 'description', 'settings', 'user_id', 'model'];
```

`database/factories/ProjectFactory.php`, im Array von `definition()` ergänzen:

```php
            'model'       => 'openai',
```

- [ ] **Step 5: Factory und Decorator anlegen**

`app/Llm/LlmFactory.php`:

```php
<?php

namespace App\Llm;

use App\Models\Project;
use InvalidArgumentException;

class LlmFactory
{
    /** The project's model, with every call logged to prompt_logs. */
    public function forProject(Project $project): LlmClient
    {
        return new LoggingLlmClient($this->adapter($project->model));
    }

    /** The bare provider adapter for a model key from config/llm.php. */
    public function adapter(string $modelKey): LlmClient
    {
        $config = ModelConfig::fromConfig($modelKey);
        $adapter = config("llm.providers.{$config->provider}");

        if (! is_string($adapter) || ! is_subclass_of($adapter, LlmClient::class)) {
            throw new InvalidArgumentException("No LLM adapter registered for provider [{$config->provider}].");
        }

        return $adapter::make($config);
    }

    /** @return array<string, string> model key → label */
    public function options(): array
    {
        return collect(config('llm.models', []))
            ->map(fn (array $entry, string $key) => $entry['label'] ?? $key)
            ->all();
    }
}
```

`app/Llm/LoggingLlmClient.php`:

```php
<?php

namespace App\Llm;

use App\Models\PromptLog;
use Throwable;

/**
 * Records every call, successful or not, in prompt_logs. Failures are counted,
 * never filtered: the study reports failure rates per model.
 */
final class LoggingLlmClient implements LlmClient
{
    public function __construct(private readonly LlmClient $inner) {}

    public function config(): ModelConfig
    {
        return $this->inner->config();
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        try {
            $response = $this->inner->complete($request);
        } catch (Throwable $e) {
            $this->record($request, null, $e);

            throw $e;
        }

        $this->record($request, $response, null);

        return $response;
    }

    public function completeMany(array $requests): array
    {
        try {
            $responses = $this->inner->completeMany($requests);
        } catch (Throwable $e) {
            foreach ($requests as $request) {
                $this->record($request, null, $e);
            }

            throw $e;
        }

        foreach ($requests as $key => $request) {
            $this->record($request, $responses[$key], null);
        }

        return $responses;
    }

    private function record(LlmRequest $request, ?LlmResponse $response, ?Throwable $error): void
    {
        $config = $this->inner->config();

        PromptLog::create([
            'job_log_id' => $request->jobLogId,
            'label' => $request->expertId === null ? $request->purpose : "{$request->purpose}:{$request->expertId}",
            'purpose' => $request->purpose,
            'expert_id' => $request->expertId,
            'provider' => $config->provider,
            'model' => $config->model,
            'checkpoint' => $response?->checkpoint,
            'config' => $config->toArray(),
            'prompt' => $request->prompt,
            'response' => $response?->text ?? '',
            'reasoning' => $response?->reasoning,
            'tokens_in' => $response?->tokensIn,
            'tokens_out' => $response?->tokensOut,
            'tokens_reasoning' => $response?->tokensReasoning,
            'latency_ms' => $response?->latencyMs,
            'status' => $error === null ? 'ok' : 'failed',
            'error' => $error === null ? null : $this->describe($error),
        ]);
    }

    private function describe(Throwable $error): string
    {
        $kind = $error instanceof LlmException ? $error->kind : LlmException::KIND_API;

        return "{$kind}: {$error->getMessage()}";
    }
}
```

`tests/Fakes/FakeLlmFactory.php`:

```php
<?php

namespace Tests\Fakes;

use App\Llm\LlmClient;
use App\Llm\LlmFactory;

/** Hands every model key the same fake; forProject() still adds logging. */
class FakeLlmFactory extends LlmFactory
{
    public function __construct(private readonly LlmClient $client) {}

    public function adapter(string $modelKey): LlmClient
    {
        return $this->client;
    }
}
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter="LlmFactoryTest|LoggingLlmClientTest|CompletesConcurrentlyTest"`
Expected: PASS, 8 Tests

- [ ] **Step 7: Gesamte Suite prüfen**

Run: `php artisan test 2>&1 | tail -3`
Expected: genau 1 Fehlschlag (`ProjectExportTest`). Die Legacy-Pipeline nutzt weiter `OpenAIClient` und ist unberührt.

- [ ] **Step 8: Commit**

```bash
git add database/migrations database/factories app/Models/PromptLog.php app/Models/Project.php app/Llm tests/Fakes tests/Unit/Llm
git commit -m "Add LlmFactory and the logging decorator" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Teil B: Pipeline-Kern

### Task 7: Schema und Models für Pipeline, Sitzplätze und Messung

**Files:**
- Modify: `database/migrations/2025_05_30_132422_create_projects_table.php`
- Modify: `database/migrations/2026_03_31_000001_create_project_contributors_table.php`
- Modify: `database/migrations/2025_05_30_132500_create_job_logs_table.php`
- Modify: `app/Models/Project.php`, `app/Models/JobLog.php`
- Modify: `config/discussion.php`
- Test: `tests/Unit/Discussion/ProjectSeatsTest.php`

**Interfaces:**
- Consumes: `projects.model` aus Task 6
- Produces:
  - Spalten `projects.pipeline` (Default `RoundRobinPipeline`), `turn_budget` (nullable), `seed`, `run_config` (JSON, nullable), `long_term_memory` (nullable), `summarized_until_message_id` (nullable)
  - Spalte `project_contributors.seat` (nullable)
  - Spalten `job_logs.turn_index`, `expert_id`, `seat`, `words`, `chars`, `thought_words`, `thought_chars`, `reasoning_tokens`, `selection` (JSON), `error`
  - `Project::contributingExperts()` liefert Experten nach `seat` sortiert, jeder mit `$expert->pivot->seat`
  - `Project::participantMessages(): HasMany` ist jetzt öffentlich
  - Config `discussion.default_pipeline`, `discussion.history_keep`, `discussion.summarize_batch`

Die Verknüpfung Turn → Nachricht existiert schon über `messages.job_log_id`; `job_logs` bekommt deshalb keine eigene `message_id`.

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Discussion/ProjectSeatsTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSeatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_experts_get_consecutive_seats_in_joining_order(): void
    {
        $project = Project::factory()->create();
        [$a, $b, $c] = Expert::factory()->count(3)->create()->all();

        $project->addContributingExpert($b);
        $project->addContributingExpert($a);
        $project->addContributingExpert($c);

        $seated = $project->contributingExperts();

        $this->assertSame([$b->id, $a->id, $c->id], $seated->pluck('id')->all());
        $this->assertSame([1, 2, 3], $seated->map(fn (Expert $e) => $e->pivot->seat)->all());
    }

    public function test_adding_the_same_expert_twice_keeps_the_seat(): void
    {
        $project = Project::factory()->create();
        $expert = Expert::factory()->create();

        $project->addContributingExpert($expert);
        $project->addContributingExpert($expert);

        $this->assertCount(1, $project->contributingExperts());
        $this->assertSame(1, $project->contributingExperts()->first()->pivot->seat);
    }

    public function test_new_projects_get_pipeline_model_and_seed_defaults(): void
    {
        $project = Project::factory()->create()->fresh();

        $this->assertSame('RoundRobinPipeline', $project->pipeline);
        $this->assertSame('openai', $project->model);
        $this->assertGreaterThan(0, $project->seed);
        $this->assertNull($project->turn_budget);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ProjectSeatsTest`
Expected: FAIL, unter anderem `Undefined property: ...::$pivot` oder `table project_contributors has no column named seat`

- [ ] **Step 3: Migrationen erweitern**

`create_projects_table.php`, nach der in Task 6 eingefügten Zeile `$table->string('model')->default('openai');`:

```php
            $table->string('pipeline')->default('RoundRobinPipeline');
            $table->unsignedInteger('turn_budget')->nullable();
            $table->unsignedBigInteger('seed')->default(1);
            $table->json('run_config')->nullable();
            $table->longText('long_term_memory')->nullable();
            // Watermark only, compared with ">". Deliberately no foreign key:
            // projects → messages → projects would be a circular constraint.
            $table->unsignedBigInteger('summarized_until_message_id')->nullable();
```

`create_project_contributors_table.php`, nach `$table->unsignedBigInteger('contributor_id');`:

```php
            $table->unsignedSmallInteger('seat')->nullable();
```

`create_job_logs_table.php`, nach `$table->json('payload')->nullable();`:

```php
            $table->unsignedInteger('turn_index')->nullable();
            $table->foreignId('expert_id')->nullable()->constrained('experts')->nullOnDelete();
            $table->unsignedSmallInteger('seat')->nullable();
            $table->unsignedInteger('words')->nullable();
            $table->unsignedInteger('chars')->nullable();
            $table->unsignedInteger('thought_words')->nullable();
            $table->unsignedInteger('thought_chars')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->json('selection')->nullable();
            $table->text('error')->nullable();
```

- [ ] **Step 4: Config ergänzen**

In `config/discussion.php` vor der schließenden `];` einfügen:

```php
    /*
    |--------------------------------------------------------------------------
    | Pipeline und Memory
    |--------------------------------------------------------------------------
    | default_pipeline — kurzer Klassenname aus app/Discussion/Pipelines.
    | history_keep     — n: so viele jüngste Nachrichten bleiben wörtlich (History).
    | summarize_batch  — b: Summarize läuft, sobald mehr als n + b Nachrichten
    |                    unzusammengefasst sind, und verdichtet alles bis auf n.
    */
    'default_pipeline' => env('DISCUSSION_DEFAULT_PIPELINE', 'RoundRobinPipeline'),
    'history_keep' => (int) env('DISCUSSION_HISTORY_KEEP', 20),
    'summarize_batch' => (int) env('DISCUSSION_SUMMARIZE_BATCH', 10),
```

- [ ] **Step 5: `Project` anpassen**

In `app/Models/Project.php`:

`$fillable` und `$casts` ersetzen:

```php
    protected $fillable = [
        'title', 'description', 'settings', 'user_id',
        'model', 'pipeline', 'turn_budget', 'seed', 'run_config',
        'long_term_memory', 'summarized_until_message_id',
    ];

    protected $casts = ['settings' => 'array', 'run_config' => 'array'];
```

`experts()` ersetzen:

```php
    public function experts(): MorphToMany {
        return $this->morphedByMany(Expert::class, 'contributor', 'project_contributors')
            ->withPivot('seat')
            ->orderBy('project_contributors.seat')
            ->orderBy('experts.id');
    }
```

`addContributingExpert()` und `removeContributingExpert()` ersetzen und `nextSeat()` ergänzen:

```php
    public function addContributingExpert(Expert $expert): void {
        if ($this->experts()->whereKey($expert->id)->exists()) {
            return;
        }

        $this->experts()->attach($expert->id, ['seat' => $this->nextSeat()]);
        $this->cachedContributingExperts = null;
    }

    public function removeContributingExpert(Expert $expert): void {
        $this->experts()->detach($expert->id);
        $this->cachedContributingExperts = null;
    }

    private function nextSeat(): int {
        return (int) $this->experts()->max('project_contributors.seat') + 1;
    }
```

Im `creating`-Hook in `booted()` nach dem `auth()`-Block ergänzen:

```php
            $project->pipeline ??= config('discussion.default_pipeline');
            $project->model ??= config('llm.default');
            $project->seed ??= random_int(1, 2_000_000_000);
```

Die Sichtbarkeit von `participantMessages()` von `private` auf `public` ändern.

- [ ] **Step 6: `JobLog` anpassen**

In `app/Models/JobLog.php` `$fillable` und `$casts` ersetzen:

```php
    protected $fillable = [
        'job_class', 'project_id', 'status', 'payload', 'started_at', 'finished_at',
        'turn_index', 'expert_id', 'seat', 'words', 'chars',
        'thought_words', 'thought_chars', 'reasoning_tokens', 'selection', 'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'selection' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
```

- [ ] **Step 7: Tests laufen lassen**

Run: `php artisan test --filter="ProjectSeatsTest|ContributorLimitTest"`
Expected: PASS

Run: `php artisan test 2>&1 | tail -3`
Expected: weiterhin genau 1 Fehlschlag (`ProjectExportTest`)

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/Project.php app/Models/JobLog.php config/discussion.php tests/Unit/Discussion/ProjectSeatsTest.php
git commit -m "Add pipeline, seat and turn measurement columns" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Wertobjekte und `TurnPayload`

**Files:**
- Create: `app/Discussion/Values/Thought.php`, `Selection.php`, `Contribution.php`
- Create: `app/Discussion/TurnPayload.php`, `app/Discussion/MissingPayloadSlot.php`
- Test: `tests/Unit/Discussion/TurnPayloadTest.php`

**Interfaces:**
- Consumes: `LlmResponse` (Task 1), `Project`, `Expert`, `Message`
- Produces:
  - `new Thought(int $expertId, string $text, ?int $priority = null, ?string $proposal = null)`
  - `new Selection(Expert $speaker, string $selector, array $signals = [], string $reasoning = '', bool $tieBroken = false)` mit `toArray(): array`
  - `new Contribution(string $text, ?string $partnerToken, ?string $pairType, LlmResponse $response)`
  - `new TurnPayload(Project $project, int $turnIndex, ?int $jobLogId = null)` mit `addThought(Thought)`, `thoughts(): array`, `thoughtOf(Expert): ?Thought`, `select(Selection)`, `selection(): Selection`, `hasSelection(): bool`, `contribute(Contribution)`, `contribution(): Contribution`, `hasContribution(): bool`, `persisted(Message)`, `message(): Message`, `hasMessage(): bool`, `halt(string $reason)`, öffentliche Felder `stop` (bool) und `reason` (`?string`)
  - `MissingPayloadSlot extends LogicException`

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Discussion/TurnPayloadTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\MissingPayloadSlot;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_a_missing_selection_names_the_fix(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);

        $this->expectException(MissingPayloadSlot::class);
        $this->expectExceptionMessage('SelectSpeaker');

        $payload->selection();
    }

    public function test_reading_a_missing_contribution_names_the_fix(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);

        $this->expectException(MissingPayloadSlot::class);
        $this->expectExceptionMessage('Speak');

        $payload->contribution();
    }

    public function test_carries_thoughts_and_selection(): void
    {
        $expert = Expert::factory()->create();
        $payload = new TurnPayload(Project::factory()->create(), 3, 42);

        $payload->addThought(new Thought($expert->id, 'Ich will widersprechen.', priority: 4));
        $payload->select(new Selection($expert, 'RoundRobinSelector', ['seat' => 1]));

        $this->assertSame(3, $payload->turnIndex);
        $this->assertSame(42, $payload->jobLogId);
        $this->assertSame(4, $payload->thoughtOf($expert)->priority);
        $this->assertTrue($payload->hasSelection());
        $this->assertSame([
            'selector' => 'RoundRobinSelector',
            'speaker_id' => $expert->id,
            'signals' => ['seat' => 1],
            'reasoning' => '',
            'tie_broken' => false,
        ], $payload->selection()->toArray());
    }

    public function test_halt_sets_stop_and_reason(): void
    {
        $payload = new TurnPayload(Project::factory()->create(), 1);

        $payload->halt('turn_budget');

        $this->assertTrue($payload->stop);
        $this->assertSame('turn_budget', $payload->reason);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=TurnPayloadTest`
Expected: FAIL mit `Class "App\Discussion\TurnPayload" not found`

- [ ] **Step 3: Wertobjekte anlegen**

`app/Discussion/Values/Thought.php`:

```php
<?php

namespace App\Discussion\Values;

/** One agent's private thought for this turn, plus whatever its Think stage added. */
final readonly class Thought
{
    public function __construct(
        public int $expertId,
        public string $text,
        public ?int $priority = null,
        public ?string $proposal = null,
    ) {}
}
```

`app/Discussion/Values/Selection.php`:

```php
<?php

namespace App\Discussion\Values;

use App\Models\Expert;

/** Who got the floor, and everything the selector knew when deciding. */
final readonly class Selection
{
    /**
     * @param  string  $selector  short class name of the selector
     * @param  array  $signals  selector-specific data per agent (bids, judge scores, seat)
     */
    public function __construct(
        public Expert $speaker,
        public string $selector,
        public array $signals = [],
        public string $reasoning = '',
        public bool $tieBroken = false,
    ) {}

    /** Stored in job_logs.selection. */
    public function toArray(): array
    {
        return [
            'selector' => $this->selector,
            'speaker_id' => $this->speaker->id,
            'signals' => $this->signals,
            'reasoning' => $this->reasoning,
            'tie_broken' => $this->tieBroken,
        ];
    }
}
```

`app/Discussion/Values/Contribution.php`:

```php
<?php

namespace App\Discussion\Values;

use App\Llm\LlmResponse;

/** The public contribution of the turn; $text is what gets counted. */
final readonly class Contribution
{
    public function __construct(
        public string $text,
        public ?string $partnerToken,
        public ?string $pairType,
        public LlmResponse $response,
    ) {}
}
```

- [ ] **Step 4: Payload anlegen**

`app/Discussion/MissingPayloadSlot.php`:

```php
<?php

namespace App\Discussion;

use LogicException;

/** A stage read a payload field that no earlier stage has written: the pipeline is mis-ordered. */
class MissingPayloadSlot extends LogicException {}
```

`app/Discussion/TurnPayload.php`:

```php
<?php

namespace App\Discussion;

use App\Discussion\Values\Contribution;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;

/**
 * The one carrier every pipeline sends through its stages. Stages write their
 * result and read what earlier stages wrote; reading a field nobody has written
 * fails loudly and says which stage is missing.
 */
class TurnPayload
{
    public bool $stop = false;

    public ?string $reason = null;

    /** @var array<int, Thought> expert id → thought */
    private array $thoughts = [];

    private ?Selection $selection = null;

    private ?Contribution $contribution = null;

    private ?Message $message = null;

    public function __construct(
        public readonly Project $project,
        public readonly int $turnIndex,
        public readonly ?int $jobLogId = null,
    ) {}

    public function addThought(Thought $thought): void
    {
        $this->thoughts[$thought->expertId] = $thought;
    }

    /** @return array<int, Thought> */
    public function thoughts(): array
    {
        return $this->thoughts;
    }

    public function thoughtOf(Expert $expert): ?Thought
    {
        return $this->thoughts[$expert->id] ?? null;
    }

    public function select(Selection $selection): void
    {
        $this->selection = $selection;
    }

    public function hasSelection(): bool
    {
        return $this->selection !== null;
    }

    public function selection(): Selection
    {
        return $this->selection
            ?? throw new MissingPayloadSlot('No speaker selected yet. Put SelectSpeaker before this stage.');
    }

    public function contribute(Contribution $contribution): void
    {
        $this->contribution = $contribution;
    }

    public function hasContribution(): bool
    {
        return $this->contribution !== null;
    }

    public function contribution(): Contribution
    {
        return $this->contribution
            ?? throw new MissingPayloadSlot('No contribution yet. Put Speak before this stage.');
    }

    public function persisted(Message $message): void
    {
        $this->message = $message;
    }

    public function hasMessage(): bool
    {
        return $this->message !== null;
    }

    public function message(): Message
    {
        return $this->message
            ?? throw new MissingPayloadSlot('No message persisted yet. Put PersistMessage before this stage.');
    }

    public function halt(string $reason): void
    {
        $this->stop = true;
        $this->reason = $reason;
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=TurnPayloadTest`
Expected: PASS, 4 Tests

- [ ] **Step 6: Commit**

```bash
git add app/Discussion tests/Unit/Discussion/TurnPayloadTest.php
git commit -m "Add the standardized turn payload" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: `TurnPipeline` und `PipelineRegistry`

**Files:**
- Create: `app/Discussion/Pipelines/TurnPipeline.php`, `PipelineRegistry.php`, `UnknownPipeline.php`
- Create: `lang/de/pipelines.php`, `lang/en/pipelines.php`
- Create: `tests/Fixtures/Pipelines/DummyPipeline.php`, `tests/Fixtures/Pipelines/NotAPipeline.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Discussion/PipelineRegistryTest.php`

**Interfaces:**
- Consumes: nichts
- Produces:
  - `interface TurnPipeline { public function stages(): array; }`; Einträge sind Stage-Klassennamen oder Laravel-Pipe-Strings `Klasse:parameter`
  - `new PipelineRegistry(string $directory, string $namespace)` mit `all(): array<string, class-string<TurnPipeline>>`, `has(string $name): bool`, `resolve(string $name): TurnPipeline`, `label(string $name): string`, `options(): array<string, string>`, `default(): string`
  - Container-Bindung: `app(PipelineRegistry::class)` scannt `app/Discussion/Pipelines`
  - `UnknownPipeline extends InvalidArgumentException`

- [ ] **Step 1: Fixtures anlegen**

`tests/Fixtures/Pipelines/DummyPipeline.php`:

```php
<?php

namespace Tests\Fixtures\Pipelines;

use App\Discussion\Pipelines\TurnPipeline;

class DummyPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [];
    }
}
```

`tests/Fixtures/Pipelines/NotAPipeline.php`:

```php
<?php

namespace Tests\Fixtures\Pipelines;

class NotAPipeline {}
```

- [ ] **Step 2: Failing test schreiben**

`tests/Unit/Discussion/PipelineRegistryTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Pipelines\UnknownPipeline;
use Tests\Fixtures\Pipelines\DummyPipeline;
use Tests\TestCase;

class PipelineRegistryTest extends TestCase
{
    private function registry(): PipelineRegistry
    {
        return new PipelineRegistry(base_path('tests/Fixtures/Pipelines'), 'Tests\\Fixtures\\Pipelines');
    }

    public function test_discovers_only_classes_that_implement_the_interface(): void
    {
        $this->assertSame(['DummyPipeline' => DummyPipeline::class], $this->registry()->all());
    }

    public function test_resolves_a_pipeline_by_its_short_class_name(): void
    {
        $this->assertInstanceOf(DummyPipeline::class, $this->registry()->resolve('DummyPipeline'));
        $this->assertTrue($this->registry()->has('DummyPipeline'));
        $this->assertFalse($this->registry()->has('NotAPipeline'));
    }

    public function test_unknown_names_throw(): void
    {
        $this->expectException(UnknownPipeline::class);

        $this->registry()->resolve('NotAPipeline');
    }

    public function test_label_falls_back_to_the_class_name(): void
    {
        $this->assertSame('DummyPipeline', $this->registry()->label('DummyPipeline'));
        $this->assertSame(['DummyPipeline' => 'DummyPipeline'], $this->registry()->options());
    }

    public function test_label_uses_a_translation_when_there_is_one(): void
    {
        app('translator')->addLines(['pipelines.DummyPipeline' => 'Attrappe'], app()->getLocale());

        $this->assertSame('Attrappe', $this->registry()->label('DummyPipeline'));
    }

    public function test_the_container_registry_scans_the_app_directory(): void
    {
        $this->assertSame(app(PipelineRegistry::class), app(PipelineRegistry::class));
        $this->assertSame('RoundRobinPipeline', app(PipelineRegistry::class)->default());
    }
}
```

- [ ] **Step 3: Test laufen lassen**

Run: `php artisan test --filter=PipelineRegistryTest`
Expected: FAIL mit `Interface "App\Discussion\Pipelines\TurnPipeline" not found`

- [ ] **Step 4: Interface, Registry und Ausnahme anlegen**

`app/Discussion/Pipelines/TurnPipeline.php`:

```php
<?php

namespace App\Discussion\Pipelines;

/**
 * One way to run a turn. A new pipeline is a new class in this directory; the
 * registry finds it, the project form lists it, the smoke test covers it.
 */
interface TurnPipeline
{
    /**
     * Ordered stages: class names, or Laravel pipe strings "Class:parameter"
     * (e.g. SelectSpeaker::with(RoundRobinSelector::class)).
     *
     * @return array<int, string>
     */
    public function stages(): array;
}
```

`app/Discussion/Pipelines/UnknownPipeline.php`:

```php
<?php

namespace App\Discussion\Pipelines;

use InvalidArgumentException;

class UnknownPipeline extends InvalidArgumentException {}
```

`app/Discussion/Pipelines/PipelineRegistry.php`:

```php
<?php

namespace App\Discussion\Pipelines;

use Illuminate\Support\Facades\Lang;
use ReflectionClass;

class PipelineRegistry
{
    /** @var array<string, class-string<TurnPipeline>>|null */
    private ?array $pipelines = null;

    public function __construct(
        private readonly string $directory,
        private readonly string $namespace,
    ) {}

    /** @return array<string, class-string<TurnPipeline>> short class name → class */
    public function all(): array
    {
        if ($this->pipelines !== null) {
            return $this->pipelines;
        }

        $found = [];

        foreach (glob($this->directory.'/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $class = $this->namespace.'\\'.$name;

            if ($this->isPipeline($class)) {
                $found[$name] = $class;
            }
        }

        ksort($found);

        return $this->pipelines = $found;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function resolve(string $name): TurnPipeline
    {
        $class = $this->all()[$name]
            ?? throw new UnknownPipeline("Unknown pipeline [{$name}]. Known: ".implode(', ', array_keys($this->all())));

        return app($class);
    }

    public function label(string $name): string
    {
        return Lang::has("pipelines.{$name}") ? __("pipelines.{$name}") : $name;
    }

    /** @return array<string, string> short class name → label, for dropdowns */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->all()) as $name) {
            $options[$name] = $this->label($name);
        }

        return $options;
    }

    public function default(): string
    {
        return (string) config('discussion.default_pipeline');
    }

    private function isPipeline(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, TurnPipeline::class)
            && ! (new ReflectionClass($class))->isAbstract();
    }
}
```

- [ ] **Step 5: Übersetzungen und Bindung anlegen**

`lang/de/pipelines.php`:

```php
<?php

// Schlüssel ist der kurze Klassenname. Fehlt ein Eintrag, zeigt die Oberfläche den Klassennamen.
return [
    'RoundRobinPipeline' => 'Round Robin (feste Reihenfolge)',
];
```

`lang/en/pipelines.php`:

```php
<?php

// Key is the short class name. Without an entry the UI shows the class name.
return [
    'RoundRobinPipeline' => 'Round robin (fixed order)',
];
```

In `app/Providers/AppServiceProvider.php` die Imports ergänzen (`use App\Discussion\Pipelines\PipelineRegistry;`) und in `register()` anfügen:

```php
        $this->app->singleton(PipelineRegistry::class, fn () => new PipelineRegistry(
            app_path('Discussion/Pipelines'),
            'App\\Discussion\\Pipelines',
        ));
```

- [ ] **Step 6: Test laufen lassen**

Run: `php artisan test --filter=PipelineRegistryTest`
Expected: PASS, 6 Tests

- [ ] **Step 7: Commit**

```bash
git add app/Discussion/Pipelines app/Providers/AppServiceProvider.php lang/de/pipelines.php lang/en/pipelines.php tests/Fixtures tests/Unit/Discussion/PipelineRegistryTest.php
git commit -m "Add the pipeline interface and auto-discovering registry" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: `PromptRenderer`, `Memory` und die gemeinsamen Prompt-Partials

**Files:**
- Create: `app/Discussion/Support/PromptRenderer.php`
- Create: `app/Discussion/Memory/Memory.php`, `app/Discussion/Memory/MemoryView.php`
- Create: `resources/views/prompts/partials/memory.blade.php`, `participants.blade.php`, `persona.blade.php`
- Modify: `resources/views/prompts/system.blade.php`
- Test: `tests/Unit/Discussion/MemoryTest.php`, `tests/Unit/Discussion/PromptRendererTest.php`

**Interfaces:**
- Consumes: `Project::participantMessages()` und `summarized_until_message_id`, `long_term_memory` aus Task 7
- Produces:
  - `new MemoryView(array $history, string $shortTerm, string $longTerm)`; `history` ist `list<array{token: ?string, name: string, content: string}>`
  - `Memory::viewFor(Project $project, ?Expert $expert = null): MemoryView`, `Memory::describe(Message $message): array`
  - `PromptRenderer::render(string $view, array $data = []): string`, `system(): string`, `participants(Project $project): array` (liefert `['experts' => Collection, 'users' => Collection]`)
  - Partials: `prompts.partials.persona` (braucht `$expert`), `prompts.partials.participants` (braucht `$experts`, `$users`), `prompts.partials.memory` (braucht `$memory`, optional `$showShortTerm`)

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/Discussion/MemoryTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Memory\Memory;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_holds_participant_messages_after_the_watermark(): void
    {
        $user = User::factory()->create(['name' => 'Jana']);
        $expert = Expert::factory()->create(['name' => 'Alice']);
        $project = Project::factory()->create();
        $project->addContributingExpert($expert);

        $project->addMessage('Systemhinweis');
        $old = $project->addMessage('Alte Nachricht', $user);
        $project->addMessage('Frage', $user);
        $project->addMessage('Antwort', $expert);

        $project->update(['summarized_until_message_id' => $old->id, 'long_term_memory' => 'Bisher ging es um X.']);

        $view = (new Memory)->viewFor($project, $expert);

        $this->assertSame([
            ['token' => "U{$user->id}", 'name' => 'Jana', 'content' => 'Frage'],
            ['token' => "E{$expert->id}", 'name' => 'Alice', 'content' => 'Antwort'],
        ], $view->history);
        $this->assertSame('Bisher ging es um X.', $view->longTerm);
    }

    public function test_short_term_is_the_experts_current_thought(): void
    {
        $expert = Expert::factory()->create();
        $project = Project::factory()->create();
        Summary::create(['project_id' => $project->id, 'expert_id' => $expert->id, 'content' => 'Ich will nachhaken.']);

        $this->assertSame('Ich will nachhaken.', (new Memory)->viewFor($project, $expert)->shortTerm);
    }

    public function test_reading_memory_writes_nothing(): void
    {
        $expert = Expert::factory()->create();
        $project = Project::factory()->create();

        $view = (new Memory)->viewFor($project, $expert);

        $this->assertSame('', $view->shortTerm);
        $this->assertSame('', $view->longTerm);
        $this->assertSame(0, Summary::count());
    }

    public function test_without_an_expert_there_is_no_short_term(): void
    {
        $project = Project::factory()->create();

        $this->assertSame('', (new Memory)->viewFor($project)->shortTerm);
    }
}
```

`tests/Unit/Discussion/PromptRendererTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Memory\MemoryView;
use App\Discussion\Support\PromptRenderer;
use App\Models\Expert;
use Tests\TestCase;

class PromptRendererTest extends TestCase
{
    public function test_decodes_html_entities_blade_escaped(): void
    {
        $expert = new Expert(['name' => "Devil's Advocate", 'job' => 'Kritiker & Prüfer', 'description' => 'Hinterfragt alles.']);
        $expert->id = 5;

        $prompt = (new PromptRenderer)->render('prompts.partials.persona', ['expert' => $expert]);

        $this->assertStringContainsString("Devil's Advocate", $prompt);
        $this->assertStringContainsString('Kritiker & Prüfer', $prompt);
        $this->assertStringNotContainsString('&#039;', $prompt);
    }

    public function test_memory_partial_renders_all_three_layers(): void
    {
        $memory = new MemoryView(
            [['token' => 'E5', 'name' => 'Alice', 'content' => 'Ich sehe das anders.']],
            'Ich will ein Beispiel bringen.',
            'Bisher: Einigkeit über das Ziel.',
        );

        $prompt = (new PromptRenderer)->render('prompts.partials.memory', ['memory' => $memory]);

        $this->assertStringContainsString('Bisher: Einigkeit über das Ziel.', $prompt);
        $this->assertStringContainsString('Alice [E5]: Ich sehe das anders.', $prompt);
        $this->assertStringContainsString('Ich will ein Beispiel bringen.', $prompt);
    }

    public function test_memory_partial_can_hide_the_private_short_term(): void
    {
        $memory = new MemoryView([], 'geheimer Gedanke', '');

        $prompt = (new PromptRenderer)->render('prompts.partials.memory', ['memory' => $memory, 'showShortTerm' => false]);

        $this->assertStringNotContainsString('geheimer Gedanke', $prompt);
    }

    public function test_system_prompt_is_rendered_from_its_view(): void
    {
        $this->assertStringContainsString('akademischen Diskussionssimulation', (new PromptRenderer)->system());
    }
}
```

- [ ] **Step 2: Tests laufen lassen**

Run: `php artisan test --filter="MemoryTest|PromptRendererTest"`
Expected: FAIL mit `Class "App\Discussion\Memory\Memory" not found`

- [ ] **Step 3: `MemoryView` und `Memory` anlegen**

`app/Discussion/Memory/MemoryView.php`:

```php
<?php

namespace App\Discussion\Memory;

/**
 * What one agent gets to see, after Nonomura et al. (2025): shared History,
 * private Short-Term thought, shared Long-Term summary.
 */
final readonly class MemoryView
{
    /**
     * @param  list<array{token: ?string, name: string, content: string}>  $history
     */
    public function __construct(
        public array $history,
        public string $shortTerm,
        public string $longTerm,
    ) {}
}
```

`app/Discussion/Memory/Memory.php`:

```php
<?php

namespace App\Discussion\Memory;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\Summary;

class Memory
{
    /**
     * Long-Term covers everything up to the project's watermark, History is
     * every participant message after it. The two never overlap. Without an
     * expert (selectors, summarizer) there is no private Short-Term layer.
     */
    public function viewFor(Project $project, ?Expert $expert = null): MemoryView
    {
        $history = $project->participantMessages()
            ->where('id', '>', $project->summarized_until_message_id ?? 0)
            ->with(['expert:id,name', 'user:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => $this->describe($message))
            ->all();

        return new MemoryView(
            history: $history,
            shortTerm: $expert === null ? '' : $this->thoughtOf($project, $expert),
            longTerm: (string) $project->long_term_memory,
        );
    }

    /** @return array{token: ?string, name: string, content: string} */
    public function describe(Message $message): array
    {
        $sender = $message->sender();

        return [
            'token' => $sender?->promptId,
            'name' => $sender?->name ?? 'System',
            'content' => $message->content,
        ];
    }

    private function thoughtOf(Project $project, Expert $expert): string
    {
        return (string) Summary::where('project_id', $project->id)
            ->where('expert_id', $expert->id)
            ->value('content');
    }
}
```

- [ ] **Step 4: `PromptRenderer` anlegen**

`app/Discussion/Support/PromptRenderer.php`:

```php
<?php

namespace App\Discussion\Support;

use App\Models\Project;

class PromptRenderer
{
    /**
     * Blade's {{ }} HTML-escapes values ("Devil&#039;s Advocate"). Prompts are
     * plain text, so entities are decoded once after rendering.
     */
    public function render(string $view, array $data = []): string
    {
        return html_entity_decode(trim(view($view, $data)->render()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function system(): string
    {
        return $this->render('prompts.system');
    }

    /** The token → name roster every agent prompt includes. */
    public function participants(Project $project): array
    {
        return [
            'experts' => $project->contributingExperts(),
            'users' => $project->users()->get()->push($project->owner)->filter()->unique('id')->values(),
        ];
    }
}
```

- [ ] **Step 5: Partials anlegen**

`resources/views/prompts/partials/persona.blade.php`:

```blade
Du bist {{ $expert->name }}, {{ $expert->job }} (dein Token: {{ $expert->promptId }}).

=== PERSONA ===
{{ $expert->description }}
```

`resources/views/prompts/partials/participants.blade.php`:

```blade
=== TEILNEHMER (Referenz-Tokens) ===
@foreach ($experts as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] ({{ $participant->job }})
@endforeach
@foreach ($users as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] (Nutzer)
@endforeach
Im sichtbaren Gespräch sprichst du Teilnehmer immer mit Namen an, niemals mit Token.
```

`resources/views/prompts/partials/memory.blade.php`:

```blade
@if ($memory->longTerm !== '')
=== LANGZEITGEDÄCHTNIS (Zusammenfassung des bisherigen Gesprächs) ===
{{ $memory->longTerm }}

@endif
=== VERLAUF (jüngste Nachrichten) ===
@forelse ($memory->history as $entry)
{{ $entry['name'] }}{{ $entry['token'] ? ' ['.$entry['token'].']' : '' }}: {{ $entry['content'] }}
@empty
Noch keine Nachrichten.
@endforelse
@if ($showShortTerm ?? true)

=== DEIN KURZZEITGEDÄCHTNIS (nur für dich sichtbar) ===
{{ $memory->shortTerm !== '' ? $memory->shortTerm : 'Noch keine Gedanken notiert.' }}
@endif
```

- [ ] **Step 6: System-Prompt von den alten Marker-Namen lösen**

In `resources/views/prompts/system.blade.php` die Zeile

```
- Halte dich an die im Prompt verlangte Ausgabestruktur (z.B. den GEDÄCHTNIS-UPDATE- oder STEUERUNG-Block) wortwörtlich.
```

ersetzen durch:

```
- Halte dich an die im Prompt verlangte Ausgabestruktur und ihre Marker wortwörtlich.
```

- [ ] **Step 7: Tests laufen lassen**

Run: `php artisan test --filter="MemoryTest|PromptRendererTest"`
Expected: PASS, 8 Tests

- [ ] **Step 8: Commit**

```bash
git add app/Discussion/Memory app/Discussion/Support/PromptRenderer.php resources/views/prompts tests/Unit/Discussion/MemoryTest.php tests/Unit/Discussion/PromptRendererTest.php
git commit -m "Add three-layer memory and shared prompt partials" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: `SpeakerSelector`, `RoundRobinSelector` und die Stage `SelectSpeaker`

Dieser Task beweist zugleich die zentrale Annahme des Entwurfs: Laravels `Pipeline` baut eine Stage aus dem String `Klasse:parameter` über den Container und reicht den Parameter an `handle()`.

**Files:**
- Create: `app/Discussion/Selectors/SpeakerSelector.php`, `RoundRobinSelector.php`
- Create: `app/Discussion/Stages/SelectSpeaker.php`
- Modify: `app/Events/PipelineStageChanged.php`
- Test: `tests/Unit/Discussion/RoundRobinSelectorTest.php`, `tests/Unit/Discussion/SelectSpeakerTest.php`

**Interfaces:**
- Consumes: `TurnPayload`, `Selection` (Task 8), `Project::contributingExperts()` mit Sitzordnung (Task 7)
- Produces:
  - `interface SpeakerSelector { public function select(TurnPayload $payload): Selection; }`
  - `RoundRobinSelector`: nächster Sitz nach dem letzten Experten-Beitrag, sonst Sitz 1
  - `SelectSpeaker::with(string $selectorClass): string` und `handle(TurnPayload $payload, Closure $next, string $selectorClass)`
  - `PipelineStageChanged::announce(int $projectId, string $stage, iterable $experts = []): void`

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/Discussion/RoundRobinSelectorTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\RoundRobinSelector;
use App\Discussion\TurnPayload;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundRobinSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var Expert[] */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    private function nextSpeakerId(): int
    {
        return (new RoundRobinSelector)->select(new TurnPayload($this->project, 1))->speaker->id;
    }

    public function test_starts_with_the_first_seat(): void
    {
        $this->assertSame($this->experts[0]->id, $this->nextSpeakerId());
    }

    public function test_moves_to_the_seat_after_the_last_speaker_and_wraps_around(): void
    {
        $this->project->addMessage('eins', $this->experts[0]);
        $this->assertSame($this->experts[1]->id, $this->nextSpeakerId());

        $this->project->addMessage('zwei', $this->experts[2]);
        $this->assertSame($this->experts[0]->id, $this->nextSpeakerId());
    }

    public function test_user_messages_do_not_shift_the_rotation(): void
    {
        $this->project->addMessage('eins', $this->experts[0]);
        $this->project->addMessage('Zwischenruf', User::factory()->create());

        $this->assertSame($this->experts[1]->id, $this->nextSpeakerId());
    }

    public function test_records_its_name_and_the_chosen_seat(): void
    {
        $selection = (new RoundRobinSelector)->select(new TurnPayload($this->project, 1));

        $this->assertSame('RoundRobinSelector', $selection->selector);
        $this->assertSame(['seat' => 1], $selection->signals);
        $this->assertFalse($selection->tieBroken);
    }
}
```

`tests/Unit/Discussion/SelectSpeakerTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\RoundRobinSelector;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class SelectSpeakerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_through_laravels_pipeline_with_the_selector_as_a_pipe_parameter(): void
    {
        Event::fake([PipelineStageChanged::class]);

        $project = Project::factory()->create();
        $expert = Expert::factory()->create();
        $project->addContributingExpert($expert);

        $payload = app(Pipeline::class)
            ->send(new TurnPayload($project, 1))
            ->through([SelectSpeaker::with(RoundRobinSelector::class)])
            ->thenReturn();

        $this->assertSame($expert->id, $payload->selection()->speaker->id);
        Event::assertDispatched(PipelineStageChanged::class, fn (PipelineStageChanged $e) => $e->stage === 'routing');
    }

    public function test_rejects_a_class_that_is_not_a_selector(): void
    {
        Event::fake([PipelineStageChanged::class]);

        $this->expectException(InvalidArgumentException::class);

        app(Pipeline::class)
            ->send(new TurnPayload(Project::factory()->create(), 1))
            ->through([SelectSpeaker::with(Project::class)])
            ->thenReturn();
    }
}
```

- [ ] **Step 2: Tests laufen lassen**

Run: `php artisan test --filter="RoundRobinSelectorTest|SelectSpeakerTest"`
Expected: FAIL mit `Class "App\Discussion\Selectors\RoundRobinSelector" not found`

- [ ] **Step 3: Interface und Selector anlegen**

`app/Discussion/Selectors/SpeakerSelector.php`:

```php
<?php

namespace App\Discussion\Selectors;

use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;

/**
 * The study's independent variable: one class per floor-control mechanism.
 * A selector never balances participation and never favours rare speakers.
 */
interface SpeakerSelector
{
    public function select(TurnPayload $payload): Selection;
}
```

`app/Discussion/Selectors/RoundRobinSelector.php`:

```php
<?php

namespace App\Discussion\Selectors;

use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/** Fixed rotation by seat: the zero point for turn share. */
class RoundRobinSelector implements SpeakerSelector
{
    public function select(TurnPayload $payload): Selection
    {
        $experts = $payload->project->contributingExperts()->values();

        $lastSpeakerId = $payload->project->messages()
            ->whereNotNull('expert_id')
            ->latest('id')
            ->value('expert_id');

        $lastIndex = $experts->search(fn (Expert $expert) => $expert->id === $lastSpeakerId);
        $nextIndex = $lastIndex === false ? 0 : ($lastIndex + 1) % $experts->count();
        $speaker = $experts[$nextIndex];

        return new Selection($speaker, class_basename(static::class), ['seat' => $speaker->pivot->seat]);
    }
}
```

- [ ] **Step 4: UI-Ereignis um eine Hilfsmethode ergänzen**

In `app/Events/PipelineStageChanged.php` nach dem Konstruktor einfügen:

```php
    /**
     * @param  iterable<\App\Models\Expert>  $experts  the experts the indicator should show
     */
    public static function announce(int $projectId, string $stage, iterable $experts = []): void
    {
        $shown = [];

        foreach ($experts as $expert) {
            $shown[] = ['id' => $expert->id, 'name' => $expert->name, 'avatar_url' => $expert->avatar_url];
        }

        static::dispatch($projectId, $stage, $shown);
    }
```

- [ ] **Step 5: Stage anlegen**

`app/Discussion/Stages/SelectSpeaker.php`:

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\Selectors\SpeakerSelector;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use Closure;
use InvalidArgumentException;

class SelectSpeaker
{
    /** Pipe string for a pipeline's stage list: SelectSpeaker::with(RoundRobinSelector::class). */
    public static function with(string $selectorClass): string
    {
        return static::class.':'.$selectorClass;
    }

    public function handle(TurnPayload $payload, Closure $next, string $selectorClass)
    {
        PipelineStageChanged::announce($payload->project->id, 'routing');

        $selector = app($selectorClass);

        if (! $selector instanceof SpeakerSelector) {
            throw new InvalidArgumentException("[{$selectorClass}] does not implement SpeakerSelector.");
        }

        $payload->select($selector->select($payload));

        return $next($payload);
    }
}
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter="RoundRobinSelectorTest|SelectSpeakerTest"`
Expected: PASS, 6 Tests

- [ ] **Step 7: Commit**

```bash
git add app/Discussion/Selectors app/Discussion/Stages/SelectSpeaker.php app/Events/PipelineStageChanged.php tests/Unit/Discussion/RoundRobinSelectorTest.php tests/Unit/Discussion/SelectSpeakerTest.php
git commit -m "Add the speaker selector seam with round robin" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: `Thinking` und die Stage `ThinkAsSpeaker`

Das Kurzzeitgedächtnis ist ein einziger fortgeschriebener Gedanke je Agent und Projekt in `summaries`. Think bekommt den bisherigen Gedanken über das Memory-Partial in den Prompt und überschreibt ihn.

**Files:**
- Create: `app/Discussion/Support/Thinking.php`
- Create: `app/Discussion/Stages/ThinkAsSpeaker.php`
- Create: `resources/views/prompts/think/speaker.blade.php`
- Test: `tests/Unit/Discussion/ThinkAsSpeakerTest.php`

**Interfaces:**
- Consumes: `LlmFactory::forProject()` (Task 6), `PromptRenderer`, `Memory` (Task 10), `TurnPayload::selection()` (Task 8), `PipelineStageChanged::announce()` (Task 11)
- Produces:
  - `Thinking::ask(TurnPayload $payload, iterable $experts, string $view, array $data = []): array<int, string>` (Expert-ID → rohe Antwort; ein Aufruf je Experte, parallel)
  - `Thinking::remember(Project $project, Expert $expert, string $thought): void`
  - `Thinking::section(string $text, string $marker, ?string $until = null): string`
  - `ThinkAsSpeaker::MARKER_THOUGHT = 'GEDANKE:'`; die Stage schreibt `Thought` in den Payload und den Gedanken nach `summaries`

Spätere Think-Stages (`ThinkAndPrioritize`, `ThinkAndPropose`) sind eigene Klassen mit eigenem View und nutzen denselben `Thinking`-Helfer.

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Discussion/ThinkAsSpeakerTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\ThinkAsSpeaker;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class ThinkAsSpeakerTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private Expert $speaker;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create(['title' => 'KI an Schulen']);
        $this->speaker = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->speaker);
        $this->project->addContributingExpert(Expert::factory()->create(['name' => 'Bob']));
    }

    private function payload(): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1, null);
        $payload->select(new Selection($this->project->contributingExperts()->first(), 'RoundRobinSelector'));

        return $payload;
    }

    public function test_only_the_selected_speaker_thinks_and_the_thought_is_kept(): void
    {
        $this->llm->push('think', "GEDANKE: Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.");

        $payload = $this->payload();
        app(ThinkAsSpeaker::class)->handle($payload, fn (TurnPayload $p) => $p);

        $this->assertCount(1, $this->llm->requestsFor('think'));
        $this->assertSame($this->speaker->id, $this->llm->requestsFor('think')[0]->expertId);
        $this->assertSame(
            'Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.',
            $payload->thoughtOf($this->speaker)->text,
        );
        $this->assertSame(
            'Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.',
            Summary::where('expert_id', $this->speaker->id)->value('content'),
        );
    }

    public function test_the_prompt_carries_persona_memory_and_the_marker(): void
    {
        Summary::create(['project_id' => $this->project->id, 'expert_id' => $this->speaker->id, 'content' => 'Mein alter Gedanke.']);
        $this->llm->push('think', 'GEDANKE: neu');

        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);

        $prompt = $this->llm->requestsFor('think')[0]->prompt;

        $this->assertStringContainsString('Du bist Alice', $prompt);
        $this->assertStringContainsString('KI an Schulen', $prompt);
        $this->assertStringContainsString('Mein alter Gedanke.', $prompt);
        $this->assertStringContainsString(ThinkAsSpeaker::MARKER_THOUGHT, $prompt);
    }

    public function test_a_second_think_overwrites_the_thought(): void
    {
        $this->llm->push('think', 'GEDANKE: erster', 'GEDANKE: zweiter');

        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);
        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);

        $this->assertSame(1, Summary::count());
        $this->assertSame('zweiter', Summary::sole()->content);
    }

    public function test_a_missing_marker_is_a_parse_failure_and_keeps_the_old_thought(): void
    {
        Summary::create(['project_id' => $this->project->id, 'expert_id' => $this->speaker->id, 'content' => 'bleibt']);
        $this->llm->push('think', 'Ich halte mich nicht an das Format.');

        try {
            app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);
            $this->fail('expected an LlmException');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_PARSE, $e->kind);
        }

        $this->assertSame('bleibt', Summary::sole()->content);
    }

    public function test_section_reads_between_two_markers(): void
    {
        $text = "Vorrede\nGEDANKE: mein Gedanke\nPRIORITÄT: 4";

        $this->assertSame('mein Gedanke', Thinking::section($text, 'GEDANKE:', 'PRIORITÄT:'));
        $this->assertSame('4', Thinking::section($text, 'PRIORITÄT:'));
        $this->assertSame('', Thinking::section($text, 'FEHLT:'));
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ThinkAsSpeakerTest`
Expected: FAIL mit `Class "App\Discussion\Stages\ThinkAsSpeaker" not found`

- [ ] **Step 3: `Thinking` anlegen**

`app/Discussion/Support/Thinking.php`:

```php
<?php

namespace App\Discussion\Support;

use App\Discussion\Memory\Memory;
use App\Discussion\TurnPayload;
use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;

/** What every Think stage shares: render per expert, ask in parallel, keep the thought. */
class Thinking
{
    public function __construct(
        private readonly LlmFactory $llm,
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
    ) {}

    /**
     * @param  iterable<Expert>  $experts
     * @return array<int, string> expert id → raw answer
     */
    public function ask(TurnPayload $payload, iterable $experts, string $view, array $data = []): array
    {
        $system = $this->prompts->system();
        $shared = $data + ['project' => $payload->project] + $this->prompts->participants($payload->project);

        $requests = [];

        foreach ($experts as $expert) {
            $prompt = $this->prompts->render($view, $shared + [
                'expert' => $expert,
                'memory' => $this->memory->viewFor($payload->project, $expert),
            ]);

            $requests[$expert->id] = new LlmRequest($system, $prompt, LlmRequest::PURPOSE_THINK, $payload->jobLogId, $expert->id);
        }

        $responses = $this->llm->forProject($payload->project)->completeMany($requests);

        return array_map(fn (LlmResponse $response) => $response->text, $responses);
    }

    /** Short-Term memory is one rolling thought per expert and project. */
    public function remember(Project $project, Expert $expert, string $thought): void
    {
        Summary::updateOrCreate(
            ['project_id' => $project->id, 'expert_id' => $expert->id],
            ['content' => $thought],
        );
    }

    /** The text after $marker, up to $until if given. '' when the marker is missing. */
    public static function section(string $text, string $marker, ?string $until = null): string
    {
        $start = mb_strpos($text, $marker);

        if ($start === false) {
            return '';
        }

        $content = mb_substr($text, $start + mb_strlen($marker));

        if ($until !== null && ($end = mb_strpos($content, $until)) !== false) {
            $content = mb_substr($content, 0, $end);
        }

        return trim($content);
    }
}
```

- [ ] **Step 4: View anlegen**

`resources/views/prompts/think/speaker.blade.php`:

```blade
@include('prompts.partials.persona', ['expert' => $expert])

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts, 'users' => $users])

@include('prompts.partials.memory', ['memory' => $memory])

=== AUFGABE ===
Du bist als Nächste oder Nächster an der Reihe. Bevor du sprichst, schreibst du dein Kurzzeitgedächtnis fort. Es ist ein einziger laufender Gedanke, den nur du siehst.

Halte darin fest:
- was dir an den jüngsten Nachrichten auffällt (Zustimmung, Widerspruch, Lücken, offene Fragen an dich),
- was dir auf der Zunge brennt, also was du als {{ $expert->name }} jetzt unbedingt sagen willst,
- was du dir für spätere Runden vornimmst, falls du jetzt nicht alles unterbringst.

Übernimm aus deinem bisherigen Kurzzeitgedächtnis, was noch gilt, und streiche, was erledigt ist. Schreibe knapp, in ganzen Sätzen, höchstens sechs Sätze. Kein Gesprächsbeitrag, keine Anrede, keine Aufzählungszeichen.

Pflichtformat, sonst nichts:
{{ $marker_thought }} <dein fortgeschriebener Gedanke>
```

- [ ] **Step 5: Stage anlegen**

`app/Discussion/Stages/ThinkAsSpeaker.php`:

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use Closure;

/** Only the already selected speaker thinks. Needs SelectSpeaker before it. */
class ThinkAsSpeaker
{
    public const MARKER_THOUGHT = 'GEDANKE:';

    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $speaker = $payload->selection()->speaker;

        PipelineStageChanged::announce($payload->project->id, 'thinking', [$speaker]);

        $answers = $this->thinking->ask($payload, [$speaker], 'prompts.think.speaker', [
            'marker_thought' => self::MARKER_THOUGHT,
        ]);

        $thought = Thinking::section($answers[$speaker->id], self::MARKER_THOUGHT);

        if ($thought === '') {
            throw new LlmException(
                "ThinkAsSpeaker: marker '".self::MARKER_THOUGHT."' missing in the answer of expert {$speaker->id}.",
                LlmException::KIND_PARSE,
            );
        }

        $this->thinking->remember($payload->project, $speaker, $thought);
        $payload->addThought(new Thought($speaker->id, $thought));

        return $next($payload);
    }
}
```

- [ ] **Step 6: Test laufen lassen**

Run: `php artisan test --filter=ThinkAsSpeakerTest`
Expected: PASS, 5 Tests

- [ ] **Step 7: Commit**

```bash
git add app/Discussion/Support/Thinking.php app/Discussion/Stages/ThinkAsSpeaker.php resources/views/prompts/think tests/Unit/Discussion/ThinkAsSpeakerTest.php
git commit -m "Add ThinkAsSpeaker with a rolling short-term thought" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 13: Stage `Speak`

Portiert SPEAK aus `AgentService`, ohne Moderator-Auftrag, ohne Nutzer-Übergabe und ohne die Eröffnungs-Historie (die hing am Moderator-Zustand). Der Marker steht einmal in der Stage.

**Files:**
- Create: `app/Discussion/Stages/Speak.php`
- Create: `resources/views/prompts/speak.blade.php`
- Test: `tests/Unit/Discussion/SpeakTest.php`

**Interfaces:**
- Consumes: `TurnPayload::selection()`, `thoughtOf()`, `LlmFactory::forProject()`, `PromptRenderer`, `Memory`, `Message::PAIR_*`
- Produces: `Speak::MARKER_CONTROL = '---STEUERUNG---'`, `Speak::PAIR_TYPES` (die vier erlaubten Paartypen); die Stage schreibt eine `Contribution` in den Payload

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Discussion/SpeakTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\Speak;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class SpeakTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private Expert $alice;

    private Expert $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create();
        $this->alice = Expert::factory()->create(['name' => 'Alice']);
        $this->bob = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->alice);
        $this->project->addContributingExpert($this->bob);
    }

    private function payload(?Thought $thought = null): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1);
        $payload->select(new Selection($this->project->contributingExperts()->first(), 'RoundRobinSelector'));

        if ($thought !== null) {
            $payload->addThought($thought);
        }

        return $payload;
    }

    private function speak(TurnPayload $payload): TurnPayload
    {
        return app(Speak::class)->handle($payload, fn (TurnPayload $p) => $p);
    }

    public function test_splits_the_visible_text_from_the_control_trailer(): void
    {
        $this->llm->push('speak', "Bob, woher nimmst du diese Zahl?\n---STEUERUNG---\nADRESSAT: E{$this->bob->id}\nPAARTYP: Frage→Antwort");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Bob, woher nimmst du diese Zahl?', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->partnerToken);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $contribution->pairType);
    }

    public function test_a_missing_trailer_degrades_to_a_plenum_contribution(): void
    {
        $this->llm->push('speak', 'Ich sehe das anders.');

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Ich sehe das anders.', $contribution->text);
        $this->assertNull($contribution->partnerToken);
        $this->assertNull($contribution->pairType);
    }

    public function test_unknown_addressees_and_pair_types_are_dropped(): void
    {
        $this->llm->push('speak', "Text.\n---STEUERUNG---\nADRESSAT: E999\nPAARTYP: Abschluss→Nutzer");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertNull($contribution->partnerToken);
        $this->assertNull($contribution->pairType);
    }

    public function test_an_empty_visible_text_is_a_parse_failure(): void
    {
        $this->llm->push('speak', "---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");

        try {
            $this->speak($this->payload());
            $this->fail('expected an LlmException');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_PARSE, $e->kind);
        }
    }

    public function test_the_prompt_carries_a_proposal_when_the_speaker_made_one(): void
    {
        $this->llm->push('speak', 'Text.');

        $this->speak($this->payload(new Thought($this->alice->id, 'Gedanke', proposal: 'Mein Entwurf lautet so.')));

        $request = $this->llm->requestsFor('speak')[0];

        $this->assertSame($this->alice->id, $request->expertId);
        $this->assertStringContainsString('Mein Entwurf lautet so.', $request->prompt);
        $this->assertStringContainsString(Speak::MARKER_CONTROL, $request->prompt);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=SpeakTest`
Expected: FAIL mit `Class "App\Discussion\Stages\Speak" not found`

- [ ] **Step 3: View anlegen**

`resources/views/prompts/speak.blade.php`:

```blade
@include('prompts.partials.persona', ['expert' => $expert])

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts, 'users' => $users])
Die Tokens brauchst du nur für die STEUERUNG-Zeile ganz am Ende.

@include('prompts.partials.memory', ['memory' => $memory])

=== REAKTIONS-TYPEN (Präferenzorganisation) ===
Bei Zustimmung: Direkt, ohne Verzögerung, ggf. mit Verstärkung ("Genau, und dazu kommt...")
Bei Teilzustimmung: Erst das Übereinstimmende benennen, dann die Differenz einführen.
Bei Ablehnung: Immer mit Verzögerungssignal beginnen ("Hmm...", "Ich bin nicht sicher, ob...", "Das kommt drauf an..."), dann partielle Zustimmung, dann Abweichung mit Begründung. Niemals direkte Verneinung ohne Abschwächung.

=== REPARATURMECHANISMEN ===
Wenn etwas unklar ist oder einer Aussage widerspricht:
Priorität 1 — Selbstreparatur: "Warte, ich meine eigentlich..." / "Lass mich das präzisieren..."
Priorität 2 — Offene Klärungsanfrage: "Was meinst du genau mit...?"
Priorität 3 — Interpretierende Klärung: "Meinst du damit, dass...?"
Niemals: Anderen direkt korrigieren ohne vorherigen Klärungsversuch.
@if (!empty($proposal))

=== DEIN ENTWURF (nur für dich sichtbar) ===
Mit diesem Entwurf hast du das Wort bekommen. Dein Beitrag soll ihm inhaltlich entsprechen.
{{ $proposal }}
@endif

=== AUFGABE ===
Verfasse jetzt deinen nächsten Gesprächsbeitrag als {{ $expert->name }}. Halte dich an deine Persona, dein Kurzzeitgedächtnis und deine Reaktions- und Reparaturregeln.

ADRESSIERUNG (Vorrang für offene Gesprächspaare):
- Richtet eine der jüngsten Äußerungen eine Frage, Bitte oder einen Einwand an dich, hat das Schließen dieses Paares klaren VORRANG: Beginne deinen Beitrag mit einer echten, substanziellen Reaktion darauf (Antwort, Zustimmung oder Widerspruch mit Begründung), bevor du etwas Neues ergänzt. Nur für diesen Bezug sind direkte Bezugnahme und kurze Bestätigung erlaubt — die "kein Echo"-Regel gilt dafür nicht.
- Wurde dir nichts gerichtet, öffne gern selbst ein Paar: richte eine konkrete Frage, Bitte oder einen pointierten Einwand gezielt an einen benannten anderen Experten, um die Diskussion zu verzahnen.
- Sprich Adressaten mit Namen an, nicht mit Token. Die formale Zuordnung trägst du nur in die STEUERUNG-Zeile am Ende ein.

LÄNGE (Standard kurz; länger ist die begründete Ausnahme):
- Standardfall sind 1-2 Sätze. Nur wenn ein Gedanke ohne Begründung, Beispiel oder kurze Herleitung nicht verständlich ist, gehst du auf höchstens 3-4 Sätze — das ist die Ausnahme, nicht die Regel. Niemals mehr.
- Jeder Satz muss Inhalt tragen: ein neues Argument, eine Zahl, ein Beispiel oder eine Schlussfolgerung. Keine Füllwörter, keine Wiederholung, keine Ausschmückung. Im Zweifel kürzer.
- Schreibe wie in einem lebendigen Chat, nicht wie in einem Essay oder Vortrag. Keine Aufzählungen, keine Überschriften, keine Einleitungsfloskeln ("Gerne...", "Ich denke, dass...").
- Wenn du nichts wirklich Neues beizutragen hast, halte dich knapp oder gib gezielt mit einer Frage an einen anderen Experten weiter.

ERÖFFNUNG (HARTE REGEL — vor dem Schreiben prüfen):
- Verboten sind generell präpositionale Rollen-Eröffnungen wie "Aus … Sicht", "Aus … Perspektive", "Im Hinblick auf …", "… betrachtet", "Auf … Ebene", "Lass uns … prüfen". Auch sinngleiche Umstellungen ("Strategisch betrachtet …", "Von der Architektur her …") fallen darunter.
- Wenn ein anderer Experte gerade mit einer Rollen-Eröffnung begonnen hat, beginnst du KEINESFALLS mit derselben Satzform — auch nicht mit einer eigenen Variante.
- Starte direkt mit einer konkreten These, einem Begriff, einem Einwand, einer Antwort oder einer Anschlussfrage. Kein Floskel-Vorlauf.
- Variiere die Satzform turn-für-turn: Wenn dein letzter Beitrag mit einer Bewertung begann, beginne diesmal mit Beispiel, Konsequenz, Bedingung oder Gegenfrage.

INHALTLICHE SUBSTANZ (verbindlich):
- Liefere konkrete Substanz: eine Definition, eine eigene These, ein Beispiel, einen Einwand mit Begründung, eine Zahl, einen Fall.
- Vermeide reine Meta-Beiträge wie "wir brauchen erst Definitionen", "lass uns Kriterien festlegen", "die Debatte braucht klare Begriffe". Wenn du Definitionen forderst, liefere im selben Turn mindestens eine.
- Wenn du keine reale Datenbasis hast, mache das transparent ("angenommen", "in einem Beispielszenario"). Erfinde keine Studien, Firmennamen oder Statistiken.

KEIN ECHO BEREITS GENANNTER FAKTEN (HARTE REGEL):
- Bevor du schreibst: liste mental auf, welche Zahlen, Fallstudien, Beispiele und Begriffe im VERLAUF bereits genannt wurden.
- Diese Datenpunkte darfst du NICHT erneut zitieren oder umformulieren — keine erneute Erwähnung, auch nicht als Bestätigung oder Aufzählung.
- Wenn du dich auf einen vorherigen Punkt beziehst, höchstens als knapper Verweis ("dazu") und mit einem NEUEN Beitrag dahinter: neue Zahl, anderer Aspekt, neuer Einwand, neues Beispiel, neue Folgerung.
- Gleicher Inhalt mit anderen Worten ist Wiederholung. Bestätigungen ohne neuen Punkt sind Wiederholung. Beides ist verboten.
- Wenn dir wirklich nichts Neues einfällt: kürzer schreiben oder explizit eine offene Folgefrage an einen anderen Experten stellen, statt Bekanntes zu paraphrasieren.

AUSGABE (verbindlich):
- Zuerst NUR der sichtbare Gesprächsbeitrag: Fließtext, Namen statt Token, keine Marker, keine Angabe eines nächsten Sprechers. Direkt danach folgt der STEUERUNG-Block (siehe unten) — und sonst nichts.
- KEINE Etiketten oder Gattungs-Präfixe vor deinem Beitrag. Beginne NIEMALS mit einem Wort plus Doppelpunkt wie "These:", "Einwand:", "Antwort:", "Frage:", "Position:", "Beispiel:", "Fazit:" o. Ä. Schreibe den Gedanken direkt als normalen Satz, ohne ihn vorab zu benennen.
- Sprich konkret zur SACHE, nie über den Diskussionsprozess, dein Gedächtnis oder deine Rolle im Ablauf.

STEUERUNG (verbindlich, NUR diese Form, NICHT Teil des sichtbaren Beitrags):
Hänge nach deinem Beitrag exakt diesen Block an:
{{ $marker_control }}
ADRESSAT: <Token des Experten, den dein Beitrag anspricht, z. B. E7 — oder "none", wenn du ans Plenum sprichst>
PAARTYP: <einer von: {{ implode(' | ', $pair_types) }}>
- ADRESSAT ist NUR ein Experten-Token aus der TEILNEHMER-Liste oder "none". Niemals ein Nutzer, niemals ein Name.
- PAARTYP: "Frage→Antwort" wenn dein Beitrag eine direkte Frage stellt, "Ansprache→Reaktion" wenn er auf eine Ansprache reagiert, "Synthese→Diskussion" wenn du verdichtest/zusammenführst, sonst "Beitrag→Diskussion".
- Die Tokens und dieser Block erscheinen ausschließlich hier, niemals im sichtbaren Beitrag darüber.
```

- [ ] **Step 4: Stage anlegen**

`app/Discussion/Stages/Speak.php`:

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Contribution;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Closure;

/** The selected speaker writes the public contribution. Needs SelectSpeaker before it. */
class Speak
{
    public const MARKER_CONTROL = '---STEUERUNG---';

    public const PAIR_TYPES = [
        Message::PAIR_FRAGE_ANTWORT,
        Message::PAIR_ANSPRACHE_REAKTION,
        Message::PAIR_BEITRAG_DISKUSSION,
        Message::PAIR_SYNTHESE_DISKUSSION,
    ];

    public function __construct(
        private readonly LlmFactory $llm,
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
    ) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $speaker = $payload->selection()->speaker;

        PipelineStageChanged::announce($payload->project->id, 'speaking', [$speaker]);

        $response = $this->llm->forProject($payload->project)->complete(new LlmRequest(
            $this->prompts->system(),
            $this->prompt($payload, $speaker),
            LlmRequest::PURPOSE_SPEAK,
            $payload->jobLogId,
            $speaker->id,
        ));

        $payload->contribute($this->parse($response, $payload->project));

        return $next($payload);
    }

    private function prompt(TurnPayload $payload, Expert $speaker): string
    {
        return $this->prompts->render('prompts.speak', [
            'expert' => $speaker,
            'project' => $payload->project,
            'memory' => $this->memory->viewFor($payload->project, $speaker),
            'proposal' => $payload->thoughtOf($speaker)?->proposal,
            'marker_control' => self::MARKER_CONTROL,
            'pair_types' => self::PAIR_TYPES,
        ] + $this->prompts->participants($payload->project));
    }

    /** The visible prose is never parsed; only the trailer after the marker is. */
    private function parse(LlmResponse $response, Project $project): Contribution
    {
        $position = mb_strpos($response->text, self::MARKER_CONTROL);

        $text = trim($position === false ? $response->text : mb_substr($response->text, 0, $position));
        $trailer = $position === false ? '' : mb_substr($response->text, $position + mb_strlen(self::MARKER_CONTROL));

        if ($text === '') {
            throw new LlmException('Speak: the answer has no visible contribution.', LlmException::KIND_PARSE);
        }

        return new Contribution($text, $this->partnerToken($trailer, $project), $this->pairType($trailer), $response);
    }

    private function partnerToken(string $trailer, Project $project): ?string
    {
        if (! preg_match('/ADRESSAT:\s*(\S+)/u', $trailer, $match)) {
            return null;
        }

        return $project->contributorByPromptId($match[1]) instanceof Expert ? $match[1] : null;
    }

    private function pairType(string $trailer): ?string
    {
        if (! preg_match('/PAARTYP:\s*(.+)/u', $trailer, $match)) {
            return null;
        }

        $value = trim($match[1]);

        return in_array($value, self::PAIR_TYPES, true) ? $value : null;
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=SpeakTest`
Expected: PASS, 5 Tests

- [ ] **Step 6: Commit**

```bash
git add app/Discussion/Stages/Speak.php resources/views/prompts/speak.blade.php tests/Unit/Discussion/SpeakTest.php
git commit -m "Add the Speak stage with a single-sourced control marker" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 14: Stage `PersistMessage`

**Files:**
- Create: `app/Discussion/Stages/PersistMessage.php`
- Test: `tests/Unit/Discussion/PersistMessageTest.php`

**Interfaces:**
- Consumes: `TurnPayload::selection()`, `contribution()`, `Project::addMessage()`, `contributorByPromptId()`
- Produces: die Stage speichert die Nachricht (mit `job_log_id`, Paartyp, Adressat) und ruft `$payload->persisted($message)`

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Discussion/PersistMessageTest.php`:

```php
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
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=PersistMessageTest`
Expected: FAIL mit `Class "App\Discussion\Stages\PersistMessage" not found`

- [ ] **Step 3: Stage anlegen**

`app/Discussion/Stages/PersistMessage.php`:

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\TurnPayload;
use App\Models\Expert;
use App\Models\Message;
use Closure;

/** Saves the public contribution. Needs SelectSpeaker and Speak before it. */
class PersistMessage
{
    public function handle(TurnPayload $payload, Closure $next)
    {
        $contribution = $payload->contribution();

        $message = $payload->project->addMessage($contribution->text, $payload->selection()->speaker);
        $message->adjacency_pair_type = $contribution->pairType ?? Message::PAIR_BEITRAG_DISKUSSION;
        $message->job_log_id = $payload->jobLogId;

        $partner = $payload->project->contributorByPromptId($contribution->partnerToken);

        if ($partner instanceof Expert) {
            $message->adjacencyPartner()->associate($partner);
        }

        $message->save();
        $payload->persisted($message);

        return $next($payload);
    }
}
```

- [ ] **Step 4: Test laufen lassen**

Run: `php artisan test --filter=PersistMessageTest`
Expected: PASS, 2 Tests

- [ ] **Step 5: Commit**

```bash
git add app/Discussion/Stages/PersistMessage.php tests/Unit/Discussion/PersistMessageTest.php
git commit -m "Add the PersistMessage stage" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 15: Stage `Summarize` mit fortgeschriebenem Langzeitgedächtnis

Behebt den Bestandsfehler, dass jeder Summarizer-Lauf die alte Zusammenfassung ersetzt statt fortschreibt, und legt die Zusammenfassung in echte Spalten statt in `project.settings`.

**Files:**
- Create: `app/Discussion/Stages/Summarize.php`
- Create: `resources/views/prompts/summarize.blade.php`
- Test: `tests/Unit/Discussion/SummarizeTest.php`

**Interfaces:**
- Consumes: `Memory::describe()`, `Project::participantMessages()`, Config `discussion.history_keep` (n) und `discussion.summarize_batch` (b), `LlmFactory::forProject()`
- Produces: die Stage schreibt `projects.long_term_memory` und `projects.summarized_until_message_id`. Sie läuft, sobald mehr als n + b Nachrichten unzusammengefasst sind, und verdichtet alles bis auf die jüngsten n. Sie darf an jeder Stelle einer Pipeline stehen.

- [ ] **Step 1: Failing test schreiben**

`tests/Unit/Discussion/SummarizeTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\Summarize;
use App\Discussion\TurnPayload;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class SummarizeTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        config(['discussion.history_keep' => 2, 'discussion.summarize_batch' => 1]);

        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create();
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    private function summarize(): void
    {
        app(Summarize::class)->handle(new TurnPayload($this->project, 1), fn (TurnPayload $p) => $p);
    }

    public function test_does_nothing_while_the_window_is_small_enough(): void
    {
        foreach (['eins', 'zwei', 'drei'] as $text) {
            $this->project->addMessage($text, $this->expert);
        }

        $this->summarize();

        $this->assertCount(0, $this->llm->requestsFor('summarize'));
        $this->assertNull($this->project->fresh()->long_term_memory);
    }

    public function test_compresses_everything_but_the_newest_n_messages(): void
    {
        $messages = [];
        foreach (['eins', 'zwei', 'drei', 'vier'] as $text) {
            $messages[] = $this->project->addMessage($text, $this->expert);
        }
        $this->llm->push('summarize', 'Zusammenfassung A.');

        $this->summarize();

        $project = $this->project->fresh();
        $this->assertSame('Zusammenfassung A.', $project->long_term_memory);
        $this->assertSame($messages[1]->id, $project->summarized_until_message_id);

        $prompt = $this->llm->requestsFor('summarize')[0]->prompt;
        $this->assertStringContainsString('Alice', $prompt);
        $this->assertStringContainsString('eins', $prompt);
        $this->assertStringContainsString('zwei', $prompt);
        $this->assertStringNotContainsString('drei', $prompt);
    }

    public function test_a_later_run_carries_the_previous_summary_forward(): void
    {
        foreach (['eins', 'zwei', 'drei', 'vier'] as $text) {
            $this->project->addMessage($text, $this->expert);
        }
        $this->llm->push('summarize', 'Zusammenfassung A.', 'Zusammenfassung B.');
        $this->summarize();

        $this->project->addMessage('fünf', $this->expert);
        $this->project->addMessage('sechs', $this->expert);
        $this->summarize();

        $second = $this->llm->requestsFor('summarize')[1]->prompt;

        $this->assertStringContainsString('Zusammenfassung A.', $second);
        $this->assertStringContainsString('drei', $second);
        $this->assertStringNotContainsString('eins', $second);
        $this->assertSame('Zusammenfassung B.', $this->project->fresh()->long_term_memory);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=SummarizeTest`
Expected: FAIL mit `Class "App\Discussion\Stages\Summarize" not found`

- [ ] **Step 3: View anlegen**

`resources/views/prompts/summarize.blade.php`:

```blade
Du bist ein neutraler Zusammenfasser. Du hast keine Persona, keine eigene Meinung und keine Präferenz für einen bestimmten Teilnehmer oder Standpunkt. Du pflegst das Langzeitgedächtnis einer Diskussion.

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

=== BISHERIGE ZUSAMMENFASSUNG ===
@if ($previous !== '')
{{ $previous }}
@else
Noch keine.
@endif

=== NEU HINZUKOMMENDE NACHRICHTEN ===
@foreach ($entries as $entry)
{{ $entry['name'] }}{{ $entry['token'] ? ' ['.$entry['token'].']' : '' }}: {{ $entry['content'] }}
@endforeach

=== AUFGABE ===
Schreibe die bisherige Zusammenfassung fort, sodass sie auch die neu hinzukommenden Nachrichten abdeckt. Das Ergebnis ersetzt die bisherige Zusammenfassung vollständig und dient künftigen Prompts als Ersatz für alle älteren Nachrichten.

Anforderungen:
- Faktisch korrekt und informationsdicht
- Keine Perspektive eines einzelnen Teilnehmers — neutral und vollständig
- Nichts aus der bisherigen Zusammenfassung verlieren, was für den weiteren Verlauf noch zählt
- Alle wesentlichen Entscheidungen, offenen Fragen, Standpunkte und Fakten müssen erhalten bleiben
- Teilnehmer nur mit ihrem Namen nennen, ohne Funktions- oder Rangbezeichnung
- Kein JSON, keine Labels, keine Überschriften, nur einfacher Fließtext
```

- [ ] **Step 4: Stage anlegen**

`app/Discussion/Stages/Summarize.php`:

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use App\Models\Message;
use Closure;

/**
 * Maintains the shared Long-Term memory. Runs once more than n + b messages
 * are unsummarized and folds all but the newest n into the rolling summary,
 * so the History window always stays between n and n + b.
 */
class Summarize
{
    public function __construct(
        private readonly LlmFactory $llm,
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
    ) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $project = $payload->project;
        $keep = (int) config('discussion.history_keep');
        $batch = (int) config('discussion.summarize_batch');

        $pending = $project->participantMessages()
            ->where('id', '>', $project->summarized_until_message_id ?? 0)
            ->with(['expert:id,name', 'user:id,name'])
            ->orderBy('id')
            ->get();

        if ($pending->count() > $keep + $batch) {
            $toCompress = $pending->take($pending->count() - $keep);

            $response = $this->llm->forProject($project)->complete(new LlmRequest(
                $this->prompts->system(),
                $this->prompts->render('prompts.summarize', [
                    'project' => $project,
                    'previous' => (string) $project->long_term_memory,
                    'entries' => $toCompress->map(fn (Message $message) => $this->memory->describe($message))->all(),
                ]),
                LlmRequest::PURPOSE_SUMMARIZE,
                $payload->jobLogId,
            ));

            $project->long_term_memory = $response->text;
            $project->summarized_until_message_id = $toCompress->last()->id;
            $project->save();
        }

        return $next($payload);
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=SummarizeTest`
Expected: PASS, 3 Tests

- [ ] **Step 6: Commit**

```bash
git add app/Discussion/Stages/Summarize.php resources/views/prompts/summarize.blade.php tests/Unit/Discussion/SummarizeTest.php
git commit -m "Add a rolling Summarize stage for long-term memory" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 16: `TurnRunner`, `RoundRobinPipeline` und der Smoke-Test

Der Runner ist der einzige Ort, der misst. Keine Pipeline kann die Messung weglassen, und auch ein fehlgeschlagener Turn hinterlässt eine Zeile.

**Files:**
- Create: `app/Discussion/Support/TextLength.php`
- Create: `app/Discussion/TurnResult.php`, `app/Discussion/TurnRunner.php`
- Create: `app/Discussion/Pipelines/RoundRobinPipeline.php`
- Test: `tests/Unit/Discussion/TextLengthTest.php`, `tests/Feature/Discussion/TurnRunnerTest.php`, `tests/Feature/Discussion/PipelineSmokeTest.php`

**Interfaces:**
- Consumes: alles aus Task 6 bis 15, Event `App\Events\JobLogged` (Signatur `JobLogged::dispatch(JobLog $log)`)
- Produces:
  - `TextLength::words(string $text): int`, `TextLength::chars(string $text): int`
  - `TurnResult::proceed(): TurnResult`, `TurnResult::stop(string $reason): TurnResult`, Felder `stop` (bool), `reason` (`?string`)
  - `TurnRunner::run(Project $project): TurnResult`; Stopp-Gründe `no_candidates`, `turn_budget`, `failed` oder der von einer Stage über `halt()` gesetzte Grund
  - `RoundRobinPipeline` mit `SelectSpeaker(RoundRobin) → ThinkAsSpeaker → Speak → PersistMessage → Summarize`

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/Discussion/TextLengthTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\TextLength;
use PHPUnit\Framework\TestCase;

class TextLengthTest extends TestCase
{
    public function test_counts_words_separated_by_any_whitespace(): void
    {
        $this->assertSame(3, TextLength::words('Alice antwortet kurz.'));
        $this->assertSame(4, TextLength::words("  Zwei  Wörter\nund\tmehr "));
        $this->assertSame(0, TextLength::words('   '));
    }

    public function test_counts_characters_not_bytes(): void
    {
        $this->assertSame(21, TextLength::chars('Alice antwortet kurz.'));
        $this->assertSame(5, TextLength::chars('Größe'));
    }
}
```

`tests/Feature/Discussion/TurnRunnerTest.php`:

```php
<?php

namespace Tests\Feature\Discussion;

use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class TurnRunnerTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    /** @var Expert[] */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class, JobLogged::class]);

        $this->llm = (new FakeLlmClient)
            ->push('think', 'GEDANKE: Ich will widersprechen.')
            ->push('speak', "Alice antwortet kurz.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create(['pipeline' => 'RoundRobinPipeline']);
        $this->experts = Expert::factory()->count(2)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    public function test_a_turn_produces_a_message_and_its_measurement(): void
    {
        $result = app(TurnRunner::class)->run($this->project);

        $this->assertFalse($result->stop);

        $message = Message::whereNotNull('expert_id')->sole();
        $this->assertSame('Alice antwortet kurz.', $message->content);

        $log = JobLog::sole();
        $this->assertSame('success', $log->status);
        $this->assertSame(1, $log->turn_index);
        $this->assertSame($this->experts[0]->id, $log->expert_id);
        $this->assertSame(1, $log->seat);
        $this->assertSame(3, $log->words);
        $this->assertSame(21, $log->chars);
        $this->assertSame(3, $log->thought_words);
        $this->assertSame('RoundRobinSelector', $log->selection['selector']);
        $this->assertSame($log->id, $message->job_log_id);
        $this->assertNotNull($log->finished_at);
    }

    public function test_every_llm_call_of_the_turn_is_logged_against_the_job_log(): void
    {
        app(TurnRunner::class)->run($this->project);

        $log = JobLog::sole();

        $this->assertSame(
            ["think:{$this->experts[0]->id}", "speak:{$this->experts[0]->id}"],
            PromptLog::where('job_log_id', $log->id)->orderBy('id')->pluck('label')->all(),
        );
    }

    public function test_consecutive_turns_rotate_and_count_up(): void
    {
        app(TurnRunner::class)->run($this->project);
        app(TurnRunner::class)->run($this->project);

        $this->assertSame([1, 2], JobLog::orderBy('id')->pluck('turn_index')->all());
        $this->assertSame(
            [$this->experts[0]->id, $this->experts[1]->id],
            JobLog::orderBy('id')->pluck('expert_id')->all(),
        );
    }

    public function test_a_failing_stage_is_recorded_and_stops_the_loop(): void
    {
        $this->llm->failOn('speak', 'verweigert', 'refusal');

        $result = app(TurnRunner::class)->run($this->project);

        $this->assertTrue($result->stop);
        $this->assertSame('failed', $result->reason);

        $log = JobLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('verweigert', $log->error);
        $this->assertSame($this->experts[0]->id, $log->expert_id);
        $this->assertNull($log->words);
        $this->assertSame(0, Message::whereNotNull('expert_id')->count());
        $this->assertSame(1, PromptLog::where('status', 'failed')->count());
    }

    public function test_stops_without_a_log_when_nobody_can_speak(): void
    {
        $empty = Project::factory()->create();

        $result = app(TurnRunner::class)->run($empty);

        $this->assertTrue($result->stop);
        $this->assertSame('no_candidates', $result->reason);
        $this->assertSame(0, JobLog::count());
    }

    public function test_stops_once_the_turn_budget_is_spent(): void
    {
        $this->project->update(['turn_budget' => 1]);

        $this->assertFalse(app(TurnRunner::class)->run($this->project)->stop);

        $result = app(TurnRunner::class)->run($this->project);

        $this->assertTrue($result->stop);
        $this->assertSame('turn_budget', $result->reason);
        $this->assertSame(1, JobLog::count());
    }

    public function test_the_first_turn_snapshots_the_run_configuration(): void
    {
        app(TurnRunner::class)->run($this->project);

        $config = $this->project->fresh()->run_config;

        $this->assertSame('RoundRobinPipeline', $config['pipeline']);
        $this->assertCount(5, $config['stages']);
        $this->assertSame('fake-model', $config['model']['model']);
        $this->assertSame(config('discussion.history_keep'), $config['history_keep']);
        $this->assertStringContainsString('Diskussionssimulation', $config['system_prompt']);
    }
}
```

`tests/Feature/Discussion/PipelineSmokeTest.php`:

```php
<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\TurnRunner;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

/**
 * Every pipeline the registry finds must survive two turns. A new pipeline is
 * covered the moment its class exists; if it needs other fake answers, extend
 * fakeAnswers().
 */
class PipelineSmokeTest extends TestCase
{
    use RefreshDatabase;

    public static function pipelineNames(): array
    {
        $names = [];

        foreach (glob(dirname(__DIR__, 3).'/app/Discussion/Pipelines/*Pipeline.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if ($name !== 'TurnPipeline' && $name !== 'UnknownPipeline') {
                $names[$name] = [$name];
            }
        }

        return $names;
    }

    private function fakeAnswers(): FakeLlmClient
    {
        return (new FakeLlmClient)
            ->push('think', 'GEDANKE: Ich will etwas beitragen.')
            ->push('speak', "Ein kurzer Beitrag.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion")
            ->push('summarize', 'Zusammenfassung.');
    }

    #[DataProvider('pipelineNames')]
    public function test_pipeline_runs_two_turns(string $name): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->fakeAnswers()));

        $this->assertTrue(app(PipelineRegistry::class)->has($name), "The registry does not find [{$name}].");

        $project = Project::factory()->create(['pipeline' => $name]);
        foreach (Expert::factory()->count(3)->create() as $expert) {
            $project->addContributingExpert($expert);
        }

        app(TurnRunner::class)->run($project);
        app(TurnRunner::class)->run($project);

        $this->assertSame(2, Message::where('project_id', $project->id)->whereNotNull('expert_id')->count());
        $this->assertSame(
            ['success', 'success'],
            JobLog::where('project_id', $project->id)->orderBy('id')->pluck('status')->all(),
        );
        $this->assertGreaterThan(0, JobLog::where('project_id', $project->id)->min('words'));
    }
}
```

- [ ] **Step 2: Tests laufen lassen**

Run: `php artisan test --filter="TextLengthTest|TurnRunnerTest|PipelineSmokeTest"`
Expected: FAIL mit `Class "App\Discussion\Support\TextLength" not found`

- [ ] **Step 3: `TextLength` und `TurnResult` anlegen**

`app/Discussion/Support/TextLength.php`:

```php
<?php

namespace App\Discussion\Support;

/** The study's two length units. Never model tokens: tokenizers differ per model family. */
final class TextLength
{
    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    public static function chars(string $text): int
    {
        return mb_strlen($text);
    }
}
```

`app/Discussion/TurnResult.php`:

```php
<?php

namespace App\Discussion;

final readonly class TurnResult
{
    private function __construct(
        public bool $stop,
        public ?string $reason,
    ) {}

    public static function proceed(): self
    {
        return new self(false, null);
    }

    public static function stop(string $reason): self
    {
        return new self(true, $reason);
    }
}
```

- [ ] **Step 4: `RoundRobinPipeline` anlegen**

`app/Discussion/Pipelines/RoundRobinPipeline.php`:

```php
<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\RoundRobinSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAsSpeaker;

/** Fixed rotation. The speaker alone thinks, after being selected. */
class RoundRobinPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            SelectSpeaker::with(RoundRobinSelector::class),
            ThinkAsSpeaker::class,
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
```

- [ ] **Step 5: `TurnRunner` anlegen**

`app/Discussion/TurnRunner.php`:

```php
<?php

namespace App\Discussion;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Pipelines\TurnPipeline;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TextLength;
use App\Events\JobLogged;
use App\Llm\LlmFactory;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs exactly one turn of whatever pipeline the project names, and measures
 * it. Measuring lives here, not in a stage, so no pipeline can leave it out and
 * failed turns are counted too. Knows nothing about queues, viewers or the UI.
 */
class TurnRunner
{
    public function __construct(
        private readonly PipelineRegistry $pipelines,
        private readonly LlmFactory $llm,
        private readonly PromptRenderer $prompts,
    ) {}

    public function run(Project $project): TurnResult
    {
        if ($project->contributingExperts()->isEmpty()) {
            return TurnResult::stop('no_candidates');
        }

        if ($this->budgetIsSpent($project)) {
            return TurnResult::stop('turn_budget');
        }

        $pipeline = $this->pipelines->resolve($project->pipeline);
        $this->snapshotRunConfig($project, $pipeline);

        $log = JobLog::create([
            'job_class' => static::class,
            'project_id' => $project->id,
            'status' => 'running',
            'started_at' => now(),
            'turn_index' => (int) JobLog::where('project_id', $project->id)->max('turn_index') + 1,
        ]);
        JobLogged::dispatch($log);

        $payload = new TurnPayload($project, $log->turn_index, $log->id);

        try {
            app(Pipeline::class)->send($payload)->through($pipeline->stages())->thenReturn();
        } catch (Throwable $e) {
            Log::error(sprintf('Turn %d of project %d failed: %s', $log->turn_index, $project->id, $e->getMessage()), ['exception' => $e]);

            $this->finish($log, $payload, 'failed', class_basename($e).': '.$e->getMessage());

            return TurnResult::stop('failed');
        }

        $this->finish($log, $payload, 'success', null);

        return $payload->stop ? TurnResult::stop((string) $payload->reason) : TurnResult::proceed();
    }

    private function budgetIsSpent(Project $project): bool
    {
        if ($project->turn_budget === null) {
            return false;
        }

        $done = JobLog::where('project_id', $project->id)->where('status', 'success')->count();

        return $done >= $project->turn_budget;
    }

    /** Freeze what this run means, once, so it stays reproducible after the code moves on. */
    private function snapshotRunConfig(Project $project, TurnPipeline $pipeline): void
    {
        if ($project->run_config !== null) {
            return;
        }

        $project->run_config = [
            'pipeline' => $project->pipeline,
            'stages' => $pipeline->stages(),
            'model' => $this->llm->forProject($project)->config()->toArray(),
            'history_keep' => (int) config('discussion.history_keep'),
            'summarize_batch' => (int) config('discussion.summarize_batch'),
            'system_prompt' => $this->prompts->system(),
        ];
        $project->save();
    }

    private function finish(JobLog $log, TurnPayload $payload, string $status, ?string $error): void
    {
        $log->update(['status' => $status, 'finished_at' => now(), 'error' => $error] + $this->measure($payload));

        JobLogged::dispatch($log->fresh());
    }

    /** Only the public contribution counts towards words and chars; thoughts are measured apart. */
    private function measure(TurnPayload $payload): array
    {
        $measured = [];

        if ($payload->hasSelection()) {
            $speaker = $payload->selection()->speaker;
            $thought = $payload->thoughtOf($speaker);

            $measured += [
                'expert_id' => $speaker->id,
                'seat' => $speaker->pivot?->seat,
                'selection' => $payload->selection()->toArray(),
                'thought_words' => $thought === null ? null : TextLength::words($thought->text),
                'thought_chars' => $thought === null ? null : TextLength::chars($thought->text),
            ];
        }

        if ($payload->hasContribution()) {
            $contribution = $payload->contribution();

            $measured += [
                'words' => TextLength::words($contribution->text),
                'chars' => TextLength::chars($contribution->text),
                'reasoning_tokens' => $contribution->response->tokensReasoning,
            ];
        }

        return $measured;
    }
}
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter="TextLengthTest|TurnRunnerTest|PipelineSmokeTest|PipelineRegistryTest"`
Expected: PASS. `PipelineRegistryTest` läuft mit, weil die Registry jetzt `RoundRobinPipeline` findet.

- [ ] **Step 7: Gesamte Suite prüfen**

Run: `php artisan test 2>&1 | tail -3`
Expected: genau 1 Fehlschlag (`ProjectExportTest`)

- [ ] **Step 8: Commit**

```bash
git add app/Discussion tests/Unit/Discussion/TextLengthTest.php tests/Feature/Discussion
git commit -m "Add the TurnRunner, the round robin pipeline and a smoke test" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Teil C: Umstellung und Aufräumarbeiten

### Task 17: `GenerationLoop` und Umstellung von `MessageGenerator`

Ab diesem Task läuft die App auf der neuen Pipeline. Die Schleifensteuerung zieht aus der Job-Basisklasse in eine eigene Klasse. Mit behoben wird ein Bestandsfehler: Der Projekt-Lock lief nach 30 Sekunden ab, ein Turn darf aber bis zu 280 Sekunden dauern, sodass sich Turns überlappen konnten.

**Files:**
- Create: `app/Discussion/GenerationLoop.php`
- Move: `app/Services/PromptingPipeline/Support/ReadingPause.php` → `app/Discussion/Support/ReadingPause.php`
- Move: `tests/Unit/Pipeline/ReadingPauseTest.php` → `tests/Unit/Discussion/ReadingPauseTest.php`
- Modify: `app/Jobs/MessageGenerator.php`, `app/Livewire/Projects/ControlChat.php`
- Delete: `app/Jobs/Dependencies/ProjectJob.php`
- Rewrite: `tests/Feature/Jobs/MessageGeneratorTest.php`
- Test: `tests/Unit/Discussion/GenerationLoopTest.php`

**Interfaces:**
- Consumes: `TurnRunner::run(Project): TurnResult` (Task 16), Events `MessageGenerated`, `GenerationStopped`
- Produces: `GenerationLoop` mit `start(int $projectId)`, `stop(int $projectId)`, `isGenerating(int $projectId): bool`, `markViewing(int $projectId)`, `hasViewers(int $projectId): bool`, `isTurnRunning(int $projectId): bool`, `withLock(int $projectId, callable $callback): void`; `new MessageGenerator(int $projectId)` mit `handle(GenerationLoop $loop, TurnRunner $runner)`

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/Discussion/GenerationLoopTest.php`:

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\GenerationLoop;
use Tests\TestCase;

class GenerationLoopTest extends TestCase
{
    public function test_the_generating_flag_can_be_raised_and_cleared(): void
    {
        $loop = new GenerationLoop;

        $this->assertFalse($loop->isGenerating(1));

        $loop->start(1);
        $this->assertTrue($loop->isGenerating(1));
        $this->assertFalse($loop->isGenerating(2));

        $loop->stop(1);
        $this->assertFalse($loop->isGenerating(1));
    }

    public function test_viewer_presence_is_tracked_per_project(): void
    {
        $loop = new GenerationLoop;

        $loop->markViewing(1);

        $this->assertTrue($loop->hasViewers(1));
        $this->assertFalse($loop->hasViewers(2));
    }

    public function test_a_turn_counts_as_running_while_the_lock_is_held(): void
    {
        $loop = new GenerationLoop;
        $seenInside = null;

        $loop->withLock(1, function () use ($loop, &$seenInside) {
            $seenInside = $loop->isTurnRunning(1);
        });

        $this->assertTrue($seenInside);
        $this->assertFalse($loop->isTurnRunning(1));
    }

    public function test_a_second_turn_does_not_start_while_the_lock_is_held(): void
    {
        $loop = new GenerationLoop;
        $innerRan = false;

        $loop->withLock(1, function () use ($loop, &$innerRan) {
            $loop->withLock(1, function () use (&$innerRan) {
                $innerRan = true;
            });
        });

        $this->assertFalse($innerRan);
    }
}
```

`tests/Feature/Jobs/MessageGeneratorTest.php` vollständig ersetzen durch:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Discussion\GenerationLoop;
use App\Discussion\TurnRunner;
use App\Events\GenerationStopped;
use App\Events\JobLogged;
use App\Events\MessageGenerated;
use App\Events\PipelineStageChanged;
use App\Jobs\MessageGenerator;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class MessageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private GenerationLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([PipelineStageChanged::class, JobLogged::class, MessageGenerated::class, GenerationStopped::class]);

        $this->llm = (new FakeLlmClient)
            ->push('think', 'GEDANKE: Ich will antworten.')
            ->push('speak', "Alice antwortet kurz.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create();
        $this->project->addContributingExpert(Expert::factory()->create(['name' => 'Alice']));

        $this->loop = app(GenerationLoop::class);
    }

    private function runJob(): void
    {
        (new MessageGenerator($this->project->id))->handle($this->loop, app(TurnRunner::class));
    }

    public function test_does_nothing_unless_the_loop_is_switched_on(): void
    {
        $this->runJob();

        $this->assertSame(0, Message::whereNotNull('expert_id')->count());
        Queue::assertNothingPushed();
    }

    public function test_generates_a_turn_and_queues_the_next_one_with_a_reading_pause(): void
    {
        $this->loop->start($this->project->id);
        $this->loop->markViewing($this->project->id);

        $this->runJob();

        $this->assertSame(1, Message::whereNotNull('expert_id')->count());
        Event::assertDispatched(MessageGenerated::class);
        Queue::assertPushed(MessageGenerator::class, fn (MessageGenerator $job) => $job->projectId === $this->project->id && $job->delay !== null);
    }

    public function test_halts_when_nobody_is_watching(): void
    {
        $this->loop->start($this->project->id);

        $this->runJob();

        $this->assertSame(1, Message::whereNotNull('expert_id')->count());
        $this->assertFalse($this->loop->isGenerating($this->project->id));
        Event::assertDispatched(GenerationStopped::class);
        Queue::assertNothingPushed();
    }

    public function test_a_failed_turn_stops_the_loop(): void
    {
        $this->llm->failOn('speak', 'kaputt');
        $this->loop->start($this->project->id);
        $this->loop->markViewing($this->project->id);

        $this->runJob();

        $this->assertFalse($this->loop->isGenerating($this->project->id));
        Event::assertDispatched(GenerationStopped::class);
        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 2: Tests laufen lassen**

Run: `php artisan test --filter="GenerationLoopTest|MessageGeneratorTest"`
Expected: FAIL mit `Class "App\Discussion\GenerationLoop" not found`

- [ ] **Step 3: `ReadingPause` umziehen**

```bash
git mv app/Services/PromptingPipeline/Support/ReadingPause.php app/Discussion/Support/ReadingPause.php
git mv tests/Unit/Pipeline/ReadingPauseTest.php tests/Unit/Discussion/ReadingPauseTest.php
```

In `app/Discussion/Support/ReadingPause.php` den Namespace auf `App\Discussion\Support` ändern. In `tests/Unit/Discussion/ReadingPauseTest.php` den Namespace auf `Tests\Unit\Discussion` und den Import auf `use App\Discussion\Support\ReadingPause;` ändern.

- [ ] **Step 4: `GenerationLoop` anlegen**

`app/Discussion/GenerationLoop.php`:

```php
<?php

namespace App\Discussion;

use Illuminate\Support\Facades\Cache;

/**
 * Shared state of the self-perpetuating generation loop, kept in the cache so
 * every user and every queue worker sees the same thing. Only the interactive
 * UI loop needs it; a headless experiment run drives TurnRunner directly.
 */
class GenerationLoop
{
    /** Must outlast the longest possible turn (MessageGenerator::$timeout), or turns could overlap. */
    private const LOCK_SECONDS = 300;

    /** Generous enough to survive background-tab poll throttling (~60s). */
    private const VIEWER_SECONDS = 90;

    /** Safety net so a crashed loop can never stay switched on. */
    private const GENERATING_MINUTES = 30;

    public function start(int $projectId): void
    {
        Cache::put($this->generatingKey($projectId), true, now()->addMinutes(self::GENERATING_MINUTES));
    }

    public function stop(int $projectId): void
    {
        Cache::forget($this->generatingKey($projectId));
    }

    public function isGenerating(int $projectId): bool
    {
        return (bool) Cache::get($this->generatingKey($projectId), false);
    }

    /** Heartbeat of an open chat view; the loop halts once nobody is watching. */
    public function markViewing(int $projectId): void
    {
        Cache::put($this->viewersKey($projectId), true, now()->addSeconds(self::VIEWER_SECONDS));
    }

    public function hasViewers(int $projectId): bool
    {
        return (bool) Cache::get($this->viewersKey($projectId), false);
    }

    public function isTurnRunning(int $projectId): bool
    {
        $lock = Cache::lock($this->lockKey($projectId));

        if ($lock->get() === false) {
            return true;
        }

        $lock->release();

        return false;
    }

    /** Runs $callback unless another turn of this project holds the lock. */
    public function withLock(int $projectId, callable $callback): void
    {
        $lock = Cache::lock($this->lockKey($projectId), self::LOCK_SECONDS);

        if ($lock->get() === false) {
            return;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }
    }

    private function generatingKey(int $projectId): string
    {
        return "project_{$projectId}_generating";
    }

    private function viewersKey(int $projectId): string
    {
        return "project_{$projectId}_viewers";
    }

    private function lockKey(int $projectId): string
    {
        return "project_{$projectId}_lock";
    }
}
```

- [ ] **Step 5: `MessageGenerator` ersetzen**

`app/Jobs/MessageGenerator.php` vollständig ersetzen durch:

```php
<?php

namespace App\Jobs;

use App\Discussion\GenerationLoop;
use App\Discussion\Support\ReadingPause;
use App\Discussion\TurnRunner;
use App\Events\GenerationStopped;
use App\Events\MessageGenerated;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The interactive UI loop: runs one turn, then queues itself again after a
 * reading pause. Everything about the turn itself lives in TurnRunner.
 */
class MessageGenerator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    public function __construct(public int $projectId) {}

    public function handle(GenerationLoop $loop, TurnRunner $runner): void
    {
        // A delayed follow-up may have been queued before the user pressed stop.
        if (! $loop->isGenerating($this->projectId)) {
            return;
        }

        $loop->withLock($this->projectId, function () use ($loop, $runner) {
            if (! $loop->isGenerating($this->projectId)) {
                return;
            }

            $project = Project::findOrFail($this->projectId);
            $continue = ! $runner->run($project)->stop;

            $latest = $project->messages()->whereNotNull('expert_id')->latest('id')->first();
            $pause = $continue && $latest !== null ? ReadingPause::secondsFor($latest->content) : 0;

            MessageGenerated::dispatch($project->id, $latest?->id, $pause);

            // Never run unattended: stop once nobody has the discussion open.
            if (! $continue || ! $loop->hasViewers($project->id)) {
                $loop->stop($project->id);
                GenerationStopped::dispatch($project->id);

                return;
            }

            // A user may have pressed stop during this turn.
            if ($loop->isGenerating($project->id)) {
                $next = static::dispatch($project->id);

                if ($pause > 0) {
                    $next->delay(now()->addSeconds($pause));
                }
            }
        });
    }
}
```

- [ ] **Step 6: `ControlChat` umstellen und `ProjectJob` löschen**

In `app/Livewire/Projects/ControlChat.php`:

- Import `use App\Jobs\Dependencies\ProjectJob;` ersetzen durch `use App\Discussion\GenerationLoop;`
- eine private Methode ergänzen:

```php
    private function loop(): GenerationLoop
    {
        return app(GenerationLoop::class);
    }
```

- alle Aufrufe ersetzen:

| alt | neu |
|---|---|
| `ProjectJob::isRunningFor($this->projectId)` | `$this->loop()->isTurnRunning($this->projectId)` |
| `ProjectJob::startGenerating($this->projectId)` | `$this->loop()->start($this->projectId)` |
| `ProjectJob::stopGenerating($this->projectId)` | `$this->loop()->stop($this->projectId)` |
| `ProjectJob::markViewing($this->projectId)` | `$this->loop()->markViewing($this->projectId)` |
| `ProjectJob::isGenerating($this->projectId)` | `$this->loop()->isGenerating($this->projectId)` |

Dann:

```bash
git rm app/Jobs/Dependencies/ProjectJob.php
grep -rn "ProjectJob" app tests
```

Expected: keine Treffer.

- [ ] **Step 7: Tests laufen lassen**

Run: `php artisan test --filter="GenerationLoopTest|MessageGeneratorTest|ReadingPauseTest"`
Expected: PASS

Run: `php artisan test 2>&1 | tail -3`
Expected: genau 1 Fehlschlag (`ProjectExportTest`). Die Legacy-Tests laufen noch, sie testen ihre Services direkt.

- [ ] **Step 8: Commit**

```bash
git add -A app/Discussion app/Jobs app/Livewire/Projects/ControlChat.php tests
git commit -m "Run the UI loop on the new TurnRunner" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 18: Projektformulare: Pipeline und Modell wählen, zwei Bestandsfehler beheben

Behoben werden: (1) `EditProject::save()` überschrieb das ganze `settings`-Array und löschte damit das Langzeitgedächtnis; (2) die Einstellung „Gedächtnisreduktion" (`summary_frequency`) wurde geschrieben, aber nirgends gelesen. Außerdem zieht die Willkommensnachricht aus dem Model-Hook in die Komponente, und der Hook hängt nicht mehr an `auth()`.

**Files:**
- Modify: `app/Livewire/Projects/CreateProject.php`, `EditProject.php`
- Modify: `resources/views/livewire/projects/create-project.blade.php`, `edit-project.blade.php`
- Modify: `app/Models/Project.php`, `app/Services/ProjectTransfer/ProjectImporter.php`
- Modify: `lang/de/projects.php`, `lang/en/projects.php`
- Test: `tests/Feature/ProjectFormsTest.php`

**Interfaces:**
- Consumes: `PipelineRegistry::options()`, `default()` (Task 9), `LlmFactory::options()` (Task 6), `projects.run_config` (Task 16)
- Produces: Projekte tragen `pipeline` und `model` aus dem Formular. Beide lassen sich nur ändern, solange `run_config` leer ist, also vor dem ersten Turn.

- [ ] **Step 1: Failing test schreiben**

`tests/Feature/ProjectFormsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_stores_pipeline_model_owner_and_a_welcome_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateProject::class)
            ->assertSet('pipeline', 'RoundRobinPipeline')
            ->set('title', 'KI an Schulen')
            ->set('description', 'Sollen Schulen KI-Werkzeuge erlauben?')
            ->set('model', 'anthropic')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();

        $this->assertSame('RoundRobinPipeline', $project->pipeline);
        $this->assertSame('anthropic', $project->model);
        $this->assertSame($user->id, $project->user_id);
        $this->assertTrue($project->users()->whereKey($user->id)->exists());
        $this->assertSame(1, $project->messages()->count());
    }

    public function test_unknown_pipelines_and_models_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->set('title', 'x')
            ->set('description', 'y')
            ->set('pipeline', 'NopePipeline')
            ->set('model', 'nope')
            ->call('save')
            ->assertHasErrors(['pipeline', 'model']);
    }

    public function test_creating_a_project_outside_a_request_needs_no_logged_in_user(): void
    {
        $owner = User::factory()->create();

        $project = Project::create(['title' => 'Lauf 1', 'description' => 'Thema', 'user_id' => $owner->id]);

        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame(0, $project->messages()->count());
    }

    public function test_editing_keeps_the_long_term_memory(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id, 'long_term_memory' => 'Bisher: X.']);
        $message = $project->addMessage('Hallo', $owner);
        $project->update(['summarized_until_message_id' => $message->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('title', 'Neuer Titel')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('Neuer Titel', $project->title);
        $this->assertSame('Bisher: X.', $project->long_term_memory);
        $this->assertSame($message->id, $project->summarized_until_message_id);
    }

    public function test_pipeline_and_model_are_frozen_once_the_run_has_started(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'model' => 'openai',
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('runStarted', true)
            ->set('model', 'gemini')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('openai', $project->fresh()->model);
    }

    public function test_pipeline_and_model_can_change_before_the_first_turn(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id, 'model' => 'openai']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('model', 'gemini')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('gemini', $project->fresh()->model);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ProjectFormsTest`
Expected: FAIL, unter anderem weil `CreateProject` keine Eigenschaft `pipeline` hat

- [ ] **Step 3: Model-Hooks von `auth()` und von der View lösen**

In `app/Models/Project.php` die Methode `booted()` ersetzen durch:

```php
    protected static function booted(): void {
        static::creating(function (Project $project): void {
            $project->pipeline ??= config('discussion.default_pipeline');
            $project->model ??= config('llm.default');
            $project->seed ??= random_int(1, 2_000_000_000);
        });
    }
```

Der `created`-Hook entfällt. Eigentümer, Teilnahme des Eigentümers und Willkommensnachricht setzt, wer das Projekt anlegt.

- [ ] **Step 4: `CreateProject` anpassen**

In `app/Livewire/Projects/CreateProject.php` die Imports um `use App\Discussion\Pipelines\PipelineRegistry;`, `use App\Llm\LlmFactory;` und `use Illuminate\Validation\Rule;` ergänzen. Die Eigenschaft `$frequency` samt Attribut entfernen. Ergänzen bzw. ersetzen:

```php
    public string $pipeline = '';

    public string $model = '';

    public function mount(PipelineRegistry $pipelines): void {
        $this->pipeline = $pipelines->default();
        $this->model    = (string) config('llm.default');
    }

    protected function rules(): array {
        return [
            'pipeline' => ['required', Rule::in(array_keys(app(PipelineRegistry::class)->options()))],
            'model'    => ['required', Rule::in(array_keys(app(LlmFactory::class)->options()))],
        ];
    }

    public function save(): void {
        $this->validate();

        $project = new Project();
        $project->user_id     = auth()->id();
        $project->title       = $this->title;
        $project->description = $this->description;
        $project->pipeline    = $this->pipeline;
        $project->model       = $this->model;
        $project->save();

        $project->addContributingUser(auth()->user());
        $project->addMessage(view('components.projects.welcome-message', ['project' => $project])->render());

        $this->redirect(route('project.show', $project), navigate: true);
    }

    public function render(): mixed {
        return view('livewire.projects.create-project', [
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models'    => app(LlmFactory::class)->options(),
        ]);
    }
```

Livewire führt die `#[Validate]`-Attribute von `title`, `description` und `importFile` mit `rules()` zusammen. Meldet der Test stattdessen, dass `title` nicht validiert wird, die Regeln der drei Attribute in `rules()` übernehmen und die Attribute entfernen.

In `resources/views/livewire/projects/create-project.blade.php` den `<flux:select wire:model.defer="frequency" ...>`-Block samt seiner drei `<option>`-Zeilen ersetzen durch:

```blade
            <flux:select wire:model.defer="pipeline" :label="__('projects.fields.pipeline')" :description="__('projects.fields.pipeline_help')">
                @foreach ($pipelines as $name => $label)
                    <option value="{{ $name }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.defer="model" :label="__('projects.fields.model')" :description="__('projects.fields.model_help')">
                @foreach ($models as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>
```

- [ ] **Step 5: `EditProject` anpassen**

In `app/Livewire/Projects/EditProject.php` dieselben drei Imports ergänzen, `$frequency` samt Attribut entfernen und ergänzen bzw. ersetzen:

```php
    public string $pipeline = '';

    public string $model = '';

    /** Pipeline and model are part of the run's identity; frozen after the first turn. */
    public bool $runStarted = false;

    public function mount(Project $project): void {
        $this->forProjectId = $project->id;
        $this->title        = $project->title;
        $this->description  = $project->description;
        $this->pipeline     = $project->pipeline;
        $this->model        = $project->model;
        $this->runStarted   = $project->run_config !== null;
    }

    protected function rules(): array {
        return [
            'pipeline' => ['required', Rule::in(array_keys(app(PipelineRegistry::class)->options()))],
            'model'    => ['required', Rule::in(array_keys(app(LlmFactory::class)->options()))],
        ];
    }

    public function save(): void {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('manage-project', $project);
        $this->validate();

        $project->title       = $this->title;
        $project->description = $this->description;

        if ($project->run_config === null) {
            $project->pipeline = $this->pipeline;
            $project->model    = $this->model;
        }

        $project->save();

        $this->dispatch('project_edited');
        Flux::modal('edit-project')->close();
    }
```

Die `render()`-Methode von `EditProject` reicht zusätzlich `'pipelines' => app(PipelineRegistry::class)->options()` und `'models' => app(LlmFactory::class)->options()` an den View.

In `resources/views/livewire/projects/edit-project.blade.php` den `frequency`-Select ersetzen durch:

```blade
            <flux:select wire:model.defer="pipeline" :label="__('projects.fields.pipeline')" :disabled="$runStarted">
                @foreach ($pipelines as $name => $label)
                    <option value="{{ $name }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.defer="model" :label="__('projects.fields.model')" :disabled="$runStarted"
                :description="$runStarted ? __('projects.fields.run_started_help') : null">
                @foreach ($models as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>
```

- [ ] **Step 6: Übersetzungen anpassen**

In `lang/de/projects.php` im Array `fields` die Einträge `memory_reduction`, `memory_reduction_help` und `reduction` entfernen und ergänzen:

```php
        'pipeline' => 'Pipeline',
        'pipeline_help' => 'Legt fest, nach welchem Verfahren der nächste Sprecher bestimmt wird.',
        'model' => 'Modell',
        'model_help' => 'Alle Agenten dieses Projekts laufen auf diesem einen Modell.',
        'run_started_help' => 'Pipeline und Modell sind fest, sobald die Diskussion begonnen hat.',
```

In `lang/en/projects.php` dieselben drei Einträge entfernen und ergänzen:

```php
        'pipeline' => 'Pipeline',
        'pipeline_help' => 'Determines how the next speaker is chosen.',
        'model' => 'Model',
        'model_help' => 'Every agent in this project runs on this one model.',
        'run_started_help' => 'Pipeline and model are fixed once the discussion has started.',
```

- [ ] **Step 7: Importer an den entfallenen Hook anpassen**

In `app/Services/ProjectTransfer/ProjectImporter.php` den Block

```php
            $project->settings    = [];
            $project->save(); // creating/created hooks add a welcome message + sync the owner

            // Drop the auto-generated welcome message so the clone matches the source.
            $project->messages()->delete();
```

ersetzen durch:

```php
            $project->settings    = [];
            $project->save();
            $project->addContributingUser($owner);
```

- [ ] **Step 8: Tests laufen lassen**

Run: `php artisan test --filter="ProjectFormsTest|ContributorLimitTest"`
Expected: PASS

Run: `php artisan test 2>&1 | tail -3`
Expected: genau 1 Fehlschlag (`ProjectExportTest`). Schlägt ein weiterer Test fehl, weil er die Willkommensnachricht oder den automatisch gesetzten Eigentümer aus dem alten Hook voraussetzt, den Test anpassen: Eigentümer und Nachricht ausdrücklich setzen.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Projects app/Models/Project.php app/Services/ProjectTransfer/ProjectImporter.php resources/views/livewire/projects lang tests/Feature/ProjectFormsTest.php
git commit -m "Choose pipeline and model per project, stop wiping run state on edit" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 19: Bestand löschen, `settings` entfernen, Export und Import anpassen

**Files:**
- Delete: `app/Services/PromptingPipeline/`, `app/Services/Clients/`, `config/apis.php`
- Delete: `resources/views/prompts/agent/`, `resources/views/prompts/moderator/`, `resources/views/prompts/shorten-chat.blade.php`
- Delete: `tests/Feature/Pipeline/`, `tests/Unit/Pipeline/`
- Modify: `database/migrations/2025_05_30_132422_create_projects_table.php`, `database/factories/ProjectFactory.php`
- Modify: `app/Models/Project.php`, `Expert.php`, `Message.php`, `Summary.php`
- Modify: `app/Services/ProjectTransfer/ProjectExport.php`, `ProjectImporter.php`
- Modify: `app/Console/Commands/BuildSuite.php`, `config/discussion.php`
- Modify: `tests/Feature/ProjectExportTest.php`, `tests/Feature/ExpertPersonaTest.php`, `tests/Unit/PromptIdTokenTest.php`
- Test: `tests/Feature/ProjectTransferTest.php`

**Interfaces:**
- Consumes: alles Bisherige
- Produces: Export-Schema Version 4 ohne `settings`, mit `pipeline`, `model`, `long_term_memory`, `summarized_until_message_id`; eine Suite ohne bekannten Fehlschlag

- [ ] **Step 1: Failing test für Export und Import schreiben**

`tests/Feature/ProjectTransferTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use App\Services\ProjectTransfer\ProjectExport;
use App\Services\ProjectTransfer\ProjectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_round_trip_keeps_pipeline_model_seats_and_memory(): void
    {
        $owner = User::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();

        $source = Project::factory()->create(['user_id' => $owner->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'gemini']);
        $source->addContributingExpert($bob);
        $source->addContributingExpert($alice);
        $first = $source->addMessage('Alt', $bob);
        $source->addMessage('Neu', $alice);
        $source->update(['long_term_memory' => 'Bisher: X.', 'summarized_until_message_id' => $first->id]);
        Summary::create(['project_id' => $source->id, 'expert_id' => $alice->id, 'content' => 'Alices Gedanke.']);

        $data = (new ProjectExport)->toArray($source->fresh());

        $this->assertSame(4, $data['schema_version']);
        $this->assertArrayNotHasKey('settings', $data['project']);

        $copy = app(ProjectImporter::class)->import($data, $owner)['project']->fresh();

        $this->assertSame('RoundRobinPipeline', $copy->pipeline);
        $this->assertSame('gemini', $copy->model);
        $this->assertSame('Bisher: X.', $copy->long_term_memory);
        $this->assertSame([$bob->id, $alice->id], $copy->contributingExperts()->pluck('id')->all());
        $this->assertSame([1, 2], $copy->contributingExperts()->map(fn (Expert $e) => $e->pivot->seat)->all());
        $this->assertSame('Alt', $copy->messages()->find($copy->summarized_until_message_id)->content);
        $this->assertSame('Alices Gedanke.', Summary::where('project_id', $copy->id)->value('content'));
        $this->assertNull($copy->run_config);
        $this->assertTrue($copy->users()->whereKey($owner->id)->exists());
    }

    public function test_unknown_pipeline_and_model_fall_back_to_the_defaults(): void
    {
        $owner = User::factory()->create();

        $copy = app(ProjectImporter::class)->import([
            'project' => ['title' => 'Alt', 'description' => 'd', 'pipeline' => 'GonePipeline', 'model' => 'gone'],
        ], $owner)['project'];

        $this->assertSame(config('discussion.default_pipeline'), $copy->pipeline);
        $this->assertSame(config('llm.default'), $copy->model);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ProjectTransferTest`
Expected: FAIL (`schema_version` ist 3, `settings` ist im Export enthalten)

- [ ] **Step 3: Bestand löschen**

```bash
git rm -r app/Services/PromptingPipeline app/Services/Clients config/apis.php
git rm -r resources/views/prompts/agent resources/views/prompts/moderator resources/views/prompts/shorten-chat.blade.php
git rm -r tests/Feature/Pipeline tests/Unit/Pipeline
```

- [ ] **Step 4: Models aufräumen**

`app/Models/Project.php`:
- aus `$fillable` den Eintrag `'settings'` entfernen, `$casts` auf `['run_config' => 'array']` kürzen
- die Methoden `handoffUser()` und `asPromptArray()` löschen

`app/Models/Expert.php`: die Methode `asPromptArray()` löschen. `thoughtsAbout()` bleibt, das Gedanken-Flyout nutzt sie.

`app/Models/Message.php`: die Methode `toPromptArray()` und die Konstante `PAIR_ABSCHLUSS_NUTZER` löschen.

`app/Models/Summary.php`: `'user_id'` aus `$fillable` entfernen und die Relation `user()` löschen. Die Spalte gab es im Schema nie.

`database/migrations/2025_05_30_132422_create_projects_table.php`: die Zeile `$table->json('settings')->default('{}');` löschen.

`database/factories/ProjectFactory.php`: den Eintrag `'settings' => [],` löschen.

- [ ] **Step 5: Config aufräumen**

In `config/discussion.php` die Blöcke „Candidate selection strategy" (`candidate_strategy`) und „Summarizer thresholds" (`summary_trigger_at`, `summary_keep_recent`) samt Kommentaren löschen. Es bleiben die Lesepausen-Werte und der Block „Pipeline und Memory" aus Task 7.

- [ ] **Step 6: Export und Import umstellen**

In `app/Services/ProjectTransfer/ProjectExport.php` `SCHEMA_VERSION` auf `4` setzen und den `'project'`-Block ersetzen durch:

```php
            'project' => [
                'title'       => $project->title,
                'description' => $project->description,
                'pipeline'    => $project->pipeline,
                'model'       => $project->model,
                'long_term_memory' => $project->long_term_memory,
                'summarized_until_message_id' => $project->summarized_until_message_id,
                'created_at'  => optional($project->created_at)->toIso8601String(),
            ],
```

`contributingExperts()` ist nach Sitz sortiert, die Expertenliste im Export trägt damit die Sitzordnung.

In `app/Services/ProjectTransfer/ProjectImporter.php`:

Imports ergänzen: `use App\Discussion\Pipelines\PipelineRegistry;`

Das Anlegen des Projekts ersetzen durch:

```php
            $project = new Project();
            $project->user_id     = $owner->id;
            $project->title       = trim((string) ($projectData['title'] ?? __('projects.import.untitled'))) . ' ' . __('projects.import.copy_suffix');
            $project->description = $projectData['description'] ?? '';
            $project->pipeline    = $this->knownPipeline($projectData['pipeline'] ?? null);
            $project->model       = $this->knownModel($projectData['model'] ?? null);
            $project->long_term_memory = $projectData['long_term_memory'] ?? null;
            $project->save();
            $project->addContributingUser($owner);
```

Das Verknüpfen der Experten

```php
            if (! empty($existingIds)) {
                $project->experts()->syncWithoutDetaching($existingIds);
            }
```

ersetzen durch eine Schleife in Exportreihenfolge, damit die Sitzplätze erhalten bleiben:

```php
            foreach ($data['experts'] ?? [] as $exported) {
                $expert = Expert::find((int) ($exported['id'] ?? 0));

                if ($expert !== null) {
                    $project->addContributingExpert($expert);
                }
            }
```

Den Schlussblock, der `settings` überträgt, ersetzen durch:

```php
            // Remap the long-term memory watermark to the recreated message.
            $watermark = (int) ($projectData['summarized_until_message_id'] ?? 0);
            $project->summarized_until_message_id = $idMap[$watermark] ?? null;
            $project->save();
```

Zwei private Methoden ergänzen:

```php
    private function knownPipeline(?string $name): string
    {
        $pipelines = app(PipelineRegistry::class);

        return $name !== null && $pipelines->has($name) ? $name : $pipelines->default();
    }

    private function knownModel(?string $key): string
    {
        return $key !== null && config("llm.models.{$key}") !== null ? $key : (string) config('llm.default');
    }
```

- [ ] **Step 7: `BuildSuite` und veraltete Tests anpassen**

In `app/Console/Commands/BuildSuite.php` die Zeile `$project->settings    = ['summary_frequency' => 10];` löschen.

In `tests/Feature/ProjectExportTest.php`:
- in beiden `Project::create([...])`-Aufrufen `'settings' => [],` entfernen
- die vier Zeilen mit `sender_type` und `sender_name` ersetzen durch die Schlüssel, die der Export wirklich liefert:

```php
            ->assertJsonPath('messages.0.is_user', true)
            ->assertJsonPath('messages.0.sender_name', $owner->name)
            ->assertJsonPath('messages.1.is_user', false)
            ->assertJsonPath('messages.1.sender_name', 'Alice');
```

In `tests/Feature/ExpertPersonaTest.php` die Methode `test_prompt_description_is_the_plain_expert_description` ersetzen durch:

```php
    public function test_the_persona_prompt_block_carries_the_plain_description(): void
    {
        $expert = Expert::factory()->create(['description' => 'Pragmatische Architektin.']);

        $prompt = app(\App\Discussion\Support\PromptRenderer::class)
            ->render('prompts.partials.persona', ['expert' => $expert]);

        $this->assertStringContainsString('Pragmatische Architektin.', $prompt);
    }
```

und den dann unbenutzten Import `use App\Models\Project;` entfernen.

In `tests/Unit/PromptIdTokenTest.php` die Methode `test_message_to_prompt_array_carries_token` löschen; `MemoryTest` deckt die Tokens im Verlauf ab.

- [ ] **Step 8: Nach Resten suchen**

```bash
grep -rnE "PromptingPipeline|OpenAIClient|bindJobLog|apis\.openai|summary_frequency|chat_summary|last_summarized_id|->settings|'settings' =>|asPromptArray|toPromptArray|handoffUser|PAIR_ABSCHLUSS_NUTZER" app config database resources tests
```

Expected: keine Treffer, die das Projekt-Feld `settings` oder die alte Pipeline meinen. Treffer unter `app/Livewire/Settings` und in Navigations-Views meinen die Einstellungsseiten und bleiben. Jeden echten Treffer beheben: Aufrufer auf die neue Struktur umstellen oder toten Code entfernen.

- [ ] **Step 9: Gedanken-Flyout prüfen**

`ExpertThoughtsFlyout` zeigt den Inhalt von `summaries` über `MemoryFormatter::parse()`. Der neue Gedanke ist Fließtext ohne `[E7]`-Marker; `parse()` liefert dafür `structured: false` und den Text in `raw`. Prüfen, dass `resources/views/livewire/projects/expert-thoughts-flyout.blade.php` im Fall `structured === false` den `raw`-Text anzeigt.

Run: `php artisan test --filter=MemoryFormatterTest`
Expected: PASS

- [ ] **Step 10: Gesamte Suite**

Run: `php artisan test 2>&1 | tail -3`
Expected: 0 Fehlschläge

Run: `vendor/bin/pint --test`
Expected: keine Beanstandungen (sonst `vendor/bin/pint` ausführen und erneut testen)

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "Remove the legacy moderator pipeline and the settings blob" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 20: Dokumentation und Probelauf

**Files:**
- Modify: `CLAUDE.md`, `docs/pipeline.puml`, `.env.example`

**Interfaces:**
- Consumes: den fertigen Stand
- Produces: Dokumentation, die den Code beschreibt, und ein von Hand geprüfter Lauf

- [ ] **Step 1: `.env.example` ergänzen**

Die Einträge `OPENAI_MODEL_FAST` und `OPENAI_MODEL_SLOW` entfernen und ergänzen:

```
LLM_DEFAULT_MODEL=openai
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5
OPENAI_REASONING_EFFORT=low
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-5
ANTHROPIC_REASONING_EFFORT=low
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-pro
DISCUSSION_DEFAULT_PIPELINE=RoundRobinPipeline
DISCUSSION_HISTORY_KEEP=20
DISCUSSION_SUMMARIZE_BATCH=10
```

- [ ] **Step 2: `CLAUDE.md` aktualisieren**

Im Abschnitt „Status of this branch" den Satz über die Legacy-Pipeline und den Abschnitt „Next architectural step" ersetzen durch den erreichten Stand: generische Pipeline vorhanden, offen sind Persona-Schicht, die drei übrigen Pipelines, Auswertungsskript und `RunExperiment`.

Den Abschnitt „Env" ersetzen durch: `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` und die Modelle in `config/llm.php`; Diskussionswerte in `config/discussion.php`.

Die Abschnitte „Generation loop", „Turn pipeline" und „Prompts & ID tokens" ersetzen durch eine Beschreibung von: `GenerationLoop` und `MessageGenerator`; `TurnRunner` (misst immer, kennt keine Bedingung); `TurnPipeline` mit `stages()`, `PipelineRegistry` (Ordner-Scan, kurzer Klassenname als Kennung); den Stages und dem `TurnPayload` mit abbrechenden Gettern; `SpeakerSelector` als einzigem Strategie-Punkt; dem Drei-Schichten-Memory; der LLM-Schicht (`LlmClient`, drei Adapter, `LoggingLlmClient`, `LlmFactory`); den Prompt-Views samt der Regel „Marker einmal in der Stage".

Dazu ein kurzer Abschnitt „Eine Pipeline hinzufügen": Klasse in `app/Discussion/Pipelines` anlegen, `stages()` zurückgeben, optional Label in `lang/*/pipelines.php`; der Smoke-Test deckt sie automatisch ab, bei neuen Aufrufzwecken `fakeAnswers()` erweitern.

Im Abschnitt „Data model notes" den Satz über `Summary.user_id` streichen und ergänzen: `summaries` hält je Experte und Projekt einen fortgeschriebenen Gedanken; Langzeitgedächtnis in `projects.long_term_memory`; `job_logs` trägt die Messung je Turn, `prompt_logs` jeden LLM-Aufruf mit Status.

Im Abschnitt „Testing" `OpenAIClient`/Mockery ersetzen durch `Tests\Fakes\FakeLlmClient` und `FakeLlmFactory`; Pfade auf `tests/Unit/Discussion`, `tests/Unit/Llm`, `tests/Feature/Discussion` ändern.

- [ ] **Step 3: `docs/pipeline.puml` ersetzen**

Das Diagramm durch den neuen Ablauf ersetzen: `MessageGenerator → TurnRunner → PipelineRegistry → RoundRobinPipeline`, darunter die fünf Stages in ihrer Reihenfolge mit den Payload-Feldern, die sie schreiben, und der Messung durch den Runner nach `job_logs`. `docs/pipeline.png` neu erzeugen, falls PlantUML lokal verfügbar ist; sonst die veraltete PNG löschen.

- [ ] **Step 4: Probelauf von Hand**

Voraussetzung: mindestens ein API-Key in `.env`.

```bash
php artisan migrate:fresh
php artisan init:experts
php artisan dev:build-suite
composer dev
```

Im Browser ein Projekt öffnen, vier Experten zuordnen, die Diskussion starten und vier Turns laufen lassen. Prüfen:
- Die Sprecher wechseln in Sitzreihenfolge.
- Der Typing-Indikator zeigt „routing", „thinking", „speaking".
- Das Gedanken-Flyout zeigt den Gedanken des Sprechers.
- Im Debug-Panel (`/debug/jobs`) steht je Turn ein Eintrag mit zwei LLM-Aufrufen (`think:<id>`, `speak:<id>`).

```bash
php artisan tinker --execute="dump(App\Models\JobLog::latest('id')->first(['turn_index','expert_id','seat','words','chars','thought_words','status'])->toArray());"
```

Expected: `status` ist `success`, `words` und `chars` sind größer als 0.

Dasselbe mit einem zweiten Projekt wiederholen, dessen Modell ein anderer Anbieter ist, sofern ein Key vorliegt. Schlägt ein Aufruf fehl, steht der Grund in `prompt_logs.error`.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/pipeline.puml docs/pipeline.png .env.example
git commit -m "Document the generic turn pipeline" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Abdeckung der Spec

| Spec | Task |
|---|---|
| 3.1 Pipeline und Registry | 9, 16 |
| 3.2 Pipelines | 16 (`RoundRobinPipeline`); die drei übrigen im Folgeplan |
| 3.3 `TurnPayload` | 8 |
| 3.4 `TurnRunner`, Messung, Stopp-Bedingungen | 16, 17 |
| 3.5 Selectors | 11 (`RoundRobinSelector`); `TieBreaker` kommt mit dem ersten Selector, der ihn braucht |
| 3.6 Memory, Summarizer-Fortschreibung | 10, 12, 15 |
| 3.7 Persona und Status | Folgeplan; das Partial `prompts.partials.persona` ist die Nahtstelle |
| 3.8 Prompts, Marker einmal, System-Prompt im Request | 10, 12, 13, 15 |
| 3.9 LLM-Schicht | 1 bis 6 |
| 4 Datenmodell | 6, 7, 19 (`status_condition`, `titled_seat` im Folgeplan) |
| 5 Aufräumarbeiten 1, 2, 4 | 18 |
| 5 Aufräumarbeit 3 (`GenerationLoop`) und der zu kurze Lock | 17 |
| 5 Aufräumarbeit 5 (Reasoning-Budget am Label) | 1, 3 |
| 5 Aufräumarbeit 6 (`ProjectTransfer`) | 18, 19 |
| 5 Aufräumarbeit 7 (Doku) | 20 |
| 6 Fehlerbehandlung | 3 bis 6, 12, 13, 16 |
| 7 Tests | jeder Task; Smoke-Test in 16 |
| Test-Baseline | 0, 19 |
