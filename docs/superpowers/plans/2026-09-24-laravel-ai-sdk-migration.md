# Umstieg auf das Laravel AI SDK: Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `App\Llm` wird durch `laravel/ai` v1.0.0 ersetzt, ohne dass sich an der Messung der
Studie eine Spalte, ein Wert oder eine Zählregel ändert.

**Architecture:** Eine Agent-Klasse je Zweck unter `App\Discussion\Agents` ersetzt die drei
handgeschriebenen Anbieter-Adapter; ein Event-Listener auf `StepCompleted`/`StepFailed` ersetzt
`LoggingLlmClient`; ein schmales `Completion` ersetzt `LlmResponse`. Die Pipeline, die Blade-Prompts
und alle Tabellen bleiben, wie sie sind.

**Tech Stack:** Laravel 12, PHP 8.3+, `laravel/ai` v1.0.0, PHPUnit 11, SQLite in-memory für Tests.

**Spec:** `docs/superpowers/specs/2026-09-24-laravel-ai-sdk-migration-design.md`

## Global Constraints

- **Keine Migration.** Keine neue Spalte, keine Umbenennung, kein Backfill. `prompt_logs`,
  `job_logs` und `projects.run_config` behalten Schema und Format.
- **`ModelConfig::toArray()` bleibt zeichengleich:** Schlüssel `key`, `provider`, `model`,
  `max_output_tokens`, `temperature`, `reasoning_effort`.
- **`Purpose`-Werte bleiben zeichengleich:** `think`, `select`, `speak`, `summarize`.
- **Kein Failover:** `prompt()` bekommt immer genau einen Provider, nie ein Array.
- **Kein `RemembersConversations`**, keine SDK-Migrationen publizieren, keine Tools, kein Streaming.
- **Anbieter-Parameter wörtlich erhalten** (sonst antworten die Modelle anders als in früheren
  Läufen):
  - OpenAI: `['reasoning' => ['effort' => $effort, 'summary' => 'auto']]`
  - Anthropic: `['thinking' => ['type' => 'adaptive', 'display' => 'summarized'], 'outputConfig' => ['effort' => $effort]]`
  - Gemini: kein Effort-Schlüssel, aber `['thinkingConfig' => ['includeThoughts' => true]]`
- **Nach jeder Task:** `php artisan test` grün, `vendor/bin/pint` ohne Befund, ein Commit.
- **Branch:** `Studie-JanNiclas-Loosen`. Kein Merge, kein Push ohne Ansage.

---

## Dateiübersicht

Neu:

| Datei | Verantwortung |
|---|---|
| `app/Discussion/Purpose.php` | Enum der vier Aufruf-Zwecke, Werte wie in `prompt_logs.purpose` |
| `app/Discussion/Values/Completion.php` | Antwort eines Modellaufrufs, prozessübergreifend serialisierbar |
| `app/Discussion/Values/ModelConfig.php` | Modell-Registry-Eintrag (zieht aus `app/Llm`), plus `lab()` |
| `app/Discussion/ParseFailure.php` | Parse-Fehler der Stages (ersetzt `LlmException::KIND_PARSE`) |
| `app/Discussion/Agents/StudyAgent.php` | gemeinsame Basis: Instructions, Modelloptionen, `ask()` |
| `app/Discussion/Agents/ThinkAgent.php` | Zweck `think` |
| `app/Discussion/Agents/SpeakAgent.php` | Zweck `speak` |
| `app/Discussion/Agents/SummarizeAgent.php` | Zweck `summarize` |
| `app/Discussion/Logging/RecordPromptLog.php` | schreibt jeden Aufruf nach `prompt_logs` |
| `app/Discussion/Logging/GuardAgainstFailover.php` | schlägt Alarm, wenn das SDK den Anbieter wechselt |
| `app/Discussion/Support/ParallelPrompts.php` | n Aufrufe gleichzeitig über Laravels `Concurrency` |
| `tests/Fakes/FakeAgents.php` | staffelt Fake-Antworten je Agent-Klasse |

Geändert: `app/Discussion/Support/Thinking.php`, `app/Discussion/Stages/Speak.php`,
`app/Discussion/Stages/Summarize.php`, `app/Discussion/Values/Contribution.php`,
`app/Discussion/TurnRunner.php` (eine Zeile), `app/Providers/AppServiceProvider.php`,
`config/llm.php`, `composer.json`, `phpunit.xml`, `CLAUDE.md`, `docs/pipeline.puml`.

> **Korrektur (nach Umsetzung):** `config/llm.php` wurde in Task 9 nicht nur geändert, sondern ganz
> gelöscht — der Nutzer hat entschieden, die Datei aufzulösen statt sie auf die Studien-Registry zu
> verschlanken. `models` und `default_model` (vormals `default`) leben jetzt in `config/ai.php`,
> siehe Task 9 Step 3.

Gelöscht in Task 9: `app/Llm/` vollständig, `tests/Fakes/FakeLlmClient.php`,
`tests/Fakes/FakeLlmFactory.php`, `tests/Unit/Llm/`, sowie — abweichend von der ursprünglichen
Planung oben — `config/llm.php` vollständig.

---

### Task 1: SDK installieren und konfigurieren

Kein Produktionscode wird umgestellt. Am Ende läuft die Suite unverändert grün, das SDK ist nur
verfügbar. `config/llm.php` bleibt in dieser Task **vollständig** — `providers` und `keys` werden
noch von `LlmFactory` gelesen, die erst in Task 9 verschwindet.

**Files:**
- Modify: `composer.json`
- Create: `config/ai.php` (publiziert, dann reduziert)
- Test: `tests/Unit/Discussion/ModelRegistryTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: `config('ai.providers')` mit den Schlüsseln `openai`, `anthropic`, `gemini`;
  installierte Klassen unter `Laravel\Ai\*`

- [ ] **Step 1: PHP-Constraint anheben und SDK installieren**

```bash
cd /home/janniclas/Projekte/LA-Communicat
# php: "^8.2" -> "^8.3" in composer.json (require-Block)
composer require laravel/ai:1.0.*   # gepinnt: Minor-Upgrades nur bewusst
```

- [ ] **Step 2: Konfiguration publizieren**

```bash
php artisan vendor:publish --tag=ai-config
```

Die Migrationen werden **nicht** publiziert. Wenn `vendor:publish` interaktiv nach dem Provider
fragt, nur den Tag `ai-config` wählen.

- [ ] **Step 3: `config/ai.php` auf die drei genutzten Anbieter reduzieren**

Aus `providers` alles außer `openai`, `anthropic`, `gemini` entfernen. `default` auf `openai`.
Alle `default_for_*`-Einträge für Bilder, Audio, Transkription, Embeddings, Reranking und
Klassifikation entfernen — diese Fähigkeiten nutzt das Projekt nicht. Die API-Keys kommen aus den
bereits vorhandenen Env-Variablen `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`.

- [ ] **Step 4: Test schreiben, der Registry und Lab-Enum aneinander bindet**

```php
<?php

namespace Tests\Unit\Discussion;

use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class ModelRegistryTest extends TestCase
{
    public function test_every_configured_model_names_a_provider_the_sdk_knows(): void
    {
        $models = config('llm.models');

        $this->assertNotEmpty($models);

        foreach ($models as $key => $entry) {
            $this->assertNotNull(
                Lab::tryFrom($entry['provider']),
                "Model [{$key}] names provider [{$entry['provider']}], which the SDK does not know.",
            );
            $this->assertArrayHasKey($entry['provider'], config('ai.providers'),
                "Provider [{$entry['provider']}] is missing from config/ai.php.");
        }
    }

    public function test_the_default_model_exists(): void
    {
        $this->assertArrayHasKey(config('llm.default'), config('llm.models'));
    }
}
```

- [ ] **Step 5: Test laufen lassen**

Run: `php artisan test --filter=ModelRegistryTest`
Expected: PASS. Schlägt er fehl, stimmen die Provider-Strings in `config/llm.php` nicht mit
`config/ai.php` oder dem `Lab`-Enum überein (`openai`, `anthropic`, `gemini`).

- [ ] **Step 6: Volle Suite**

Run: `php artisan test`
Expected: 154 passed — die 152 bestehenden plus die zwei neuen. Entscheidend ist, dass kein
bestehender Test fehlschlägt: in dieser Task wurde noch kein Produktionscode angefasst.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock config/ai.php tests/Unit/Discussion/ModelRegistryTest.php
git commit -m "Install the Laravel AI SDK alongside the existing LLM layer"
```

---

### Task 2: Purpose, Completion und ModelConfig

**Files:**
- Create: `app/Discussion/Purpose.php`
- Create: `app/Discussion/Values/Completion.php`
- Create: `app/Discussion/Values/ModelConfig.php`
- Delete: `app/Llm/ModelConfig.php` (erst nachdem die Referenzen umgestellt sind, siehe Step 5)
- Test: `tests/Unit/Discussion/ModelConfigTest.php` (zieht aus `tests/Unit/Llm/ModelConfigTest.php`)

**Interfaces:**
- Consumes: `config('llm.models')`
- Produces:
  - `App\Discussion\Purpose` mit `Think`, `Select`, `Speak`, `Summarize` und `->value`
  - `App\Discussion\Values\Completion(string $text, string $reasoning, int $inputTokens, int $outputTokens, ?int $reasoningTokens)`
  - `App\Discussion\Values\ModelConfig` mit `fromConfig(string $key): self`, `toArray(): array`,
    `lab(): Lab` und den Properties `key`, `label`, `provider`, `model`, `maxOutputTokens`,
    `temperature`, `reasoningEffort`

- [ ] **Step 1: Failing test für `lab()` und den unveränderten Snapshot**

`tests/Unit/Discussion/ModelConfigTest.php` — den bestehenden Inhalt von
`tests/Unit/Llm/ModelConfigTest.php` übernehmen, Namespace und Import auf
`App\Discussion\Values\ModelConfig` ändern, und diese zwei Tests ergänzen:

```php
public function test_the_provider_string_maps_onto_the_sdk_lab_enum(): void
{
    config()->set('llm.models.probe', [
        'label' => 'Probe', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
        'max_output_tokens' => 8000, 'temperature' => null, 'reasoning_effort' => 'low',
    ]);

    $this->assertSame(Lab::Anthropic, ModelConfig::fromConfig('probe')->lab());
}

public function test_the_snapshot_keys_are_unchanged(): void
{
    config()->set('llm.models.probe', [
        'label' => 'Probe', 'provider' => 'openai', 'model' => 'gpt-5',
        'max_output_tokens' => 8000, 'temperature' => null, 'reasoning_effort' => 'low',
    ]);

    $this->assertSame(
        ['key', 'provider', 'model', 'max_output_tokens', 'temperature', 'reasoning_effort'],
        array_keys(ModelConfig::fromConfig('probe')->toArray()),
    );
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ModelConfigTest`
Expected: FAIL — `Class "App\Discussion\Values\ModelConfig" not found`.

- [ ] **Step 3: Purpose, Completion und ModelConfig anlegen**

```php
<?php

namespace App\Discussion;

/** The four kinds of model call. Values are stored in prompt_logs.purpose. */
enum Purpose: string
{
    case Think = 'think';
    case Select = 'select';
    case Speak = 'speak';
    case Summarize = 'summarize';
}
```

```php
<?php

namespace App\Discussion\Values;

/**
 * One model answer. Deliberately narrow: these objects cross a process boundary
 * when prompts run in parallel, so nothing here may hold a connection or a
 * response object. reasoningTokens stays nullable — not every provider reports it,
 * and null must not be confused with zero.
 */
final readonly class Completion
{
    public function __construct(
        public string $text,
        public string $reasoning,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $reasoningTokens,
    ) {}
}
```

`app/Discussion/Values/ModelConfig.php`: den Inhalt von `app/Llm/ModelConfig.php` übernehmen,
Namespace auf `App\Discussion\Values` ändern, `toArray()` unverändert lassen und ergänzen:

```php
public function lab(): Lab
{
    return Lab::tryFrom($this->provider)
        ?? throw new InvalidArgumentException("Provider [{$this->provider}] is unknown to the AI SDK.");
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `php artisan test --filter=ModelConfigTest`
Expected: PASS. `Completion` bekommt keinen eigenen Test — ein readonly-Wertobjekt ohne Logik wird
durch die Tests der Tasks 4 bis 8 mitgetragen.

- [ ] **Step 5: Alte ModelConfig zum Alias machen, damit `app/Llm` weiterläuft**

`app/Llm/ModelConfig.php` wird in dieser Task **noch nicht** gelöscht — `LlmClient`,
`LoggingLlmClient` und die drei Adapter typisieren darauf. Stattdessen bleibt die alte Klasse
unverändert stehen; die neue lebt daneben. Beide verschwinden bzw. bleiben in Task 9, wenn
`app/Llm` gelöscht wird. Doppelte Wahrheit ist hier bewusst und dauert drei Tasks.

- [ ] **Step 6: Volle Suite und Commit**

```bash
php artisan test
vendor/bin/pint
git add app/Discussion/Purpose.php app/Discussion/Values/Completion.php \
        app/Discussion/Values/ModelConfig.php tests/Unit/Discussion/
git commit -m "Add Purpose, Completion and the Discussion-side ModelConfig"
```

---

### Task 3: Die Agenten

**Files:**
- Create: `app/Discussion/Agents/StudyAgent.php`
- Create: `app/Discussion/Agents/ThinkAgent.php`
- Create: `app/Discussion/Agents/SpeakAgent.php`
- Create: `app/Discussion/Agents/SummarizeAgent.php`
- Test: `tests/Unit/Discussion/StudyAgentTest.php`

**Interfaces:**
- Consumes: `ModelConfig`, `Purpose`, `PromptRenderer`, `Completion`
- Produces: `StudyAgent::__construct(ModelConfig $model, ?int $jobLogId = null, ?int $expertId = null)`,
  `StudyAgent::ask(string $prompt): Completion`, `StudyAgent::purpose(): Purpose`,
  Unterklassen `ThinkAgent`, `SpeakAgent`, `SummarizeAgent`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Purpose;
use App\Discussion\Values\ModelConfig;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class StudyAgentTest extends TestCase
{
    private function model(string $provider, ?string $effort = 'low', ?float $temperature = null): ModelConfig
    {
        return new ModelConfig(
            key: 'probe', label: 'Probe', provider: $provider, model: 'probe-model',
            maxOutputTokens: 8000, temperature: $temperature, reasoningEffort: $effort,
        );
    }

    public function test_each_agent_carries_its_purpose(): void
    {
        $this->assertSame(Purpose::Think, (new ThinkAgent($this->model('openai')))->purpose());
        $this->assertSame(Purpose::Speak, (new SpeakAgent($this->model('openai')))->purpose());
        $this->assertSame(Purpose::Summarize, (new SummarizeAgent($this->model('openai')))->purpose());
    }

    public function test_instructions_are_the_shared_system_prompt(): void
    {
        $agent = new SpeakAgent($this->model('openai'));

        $this->assertSame(app(\App\Discussion\Support\PromptRenderer::class)->system(), $agent->instructions());
    }

    public function test_model_options_come_from_the_model_config(): void
    {
        $agent = new SpeakAgent($this->model('openai', temperature: 0.4));

        $this->assertSame(8000, $agent->maxTokens());
        $this->assertSame(0.4, $agent->temperature());
    }

    public function test_openai_gets_effort_and_a_summary_request(): void
    {
        $options = (new SpeakAgent($this->model('openai')))->providerOptions(Lab::OpenAI);

        $this->assertSame(['reasoning' => ['effort' => 'low', 'summary' => 'auto']], $options);
    }

    public function test_anthropic_gets_adaptive_thinking_and_an_output_effort(): void
    {
        $options = (new SpeakAgent($this->model('anthropic')))->providerOptions(Lab::Anthropic);

        $this->assertSame([
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'outputConfig' => ['effort' => 'low'],
        ], $options);
    }

    public function test_gemini_asks_for_thoughts_but_never_for_an_effort_level(): void
    {
        $options = (new SpeakAgent($this->model('gemini', effort: null)))->providerOptions(Lab::Gemini);

        $this->assertSame(['thinkingConfig' => ['includeThoughts' => true]], $options);
    }

    public function test_an_effort_on_gemini_is_rejected_instead_of_silently_dropped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SpeakAgent($this->model('gemini', effort: 'low')))->providerOptions(Lab::Gemini);
    }

    public function test_a_null_effort_sends_no_reasoning_options_at_all(): void
    {
        $this->assertSame([], (new SpeakAgent($this->model('openai', effort: null)))->providerOptions(Lab::OpenAI));
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=StudyAgentTest`
Expected: FAIL — `Class "App\Discussion\Agents\ThinkAgent" not found`.

- [ ] **Step 3: StudyAgent implementieren**

```php
<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\Completion;
use App\Discussion\Values\ModelConfig;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * What every model call in this study shares. The metadata travels with the
 * agent because the SDK hands the agent instance to its Step events, which is
 * where prompt_logs gets written.
 *
 * The renderer is resolved from the container instead of injected: agents are
 * built with `new` inside closures that cross a process boundary when prompts
 * run in parallel, so the object must stay free of bound services.
 */
abstract class StudyAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(
        public readonly ModelConfig $model,
        public readonly ?int $jobLogId = null,
        public readonly ?int $expertId = null,
    ) {}

    abstract public function purpose(): Purpose;

    public function instructions(): string
    {
        return app(PromptRenderer::class)->system();
    }

    public function maxTokens(): ?int
    {
        return $this->model->maxOutputTokens;
    }

    public function temperature(): ?float
    {
        return $this->model->temperature;
    }

    /**
     * The reasoning parameters each provider expects. These are carried over
     * verbatim from the adapters they replace: a different shape means the model
     * answers differently, and runs stop being comparable.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $effort = $this->model->reasoningEffort;
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        if ($lab === Lab::Gemini) {
            if ($effort !== null) {
                throw new InvalidArgumentException(
                    "Gemini has no reasoning-effort levels; model key [{$this->model->key}] sets one."
                );
            }

            return ['thinkingConfig' => ['includeThoughts' => true]];
        }

        if ($effort === null) {
            return [];
        }

        return match ($lab) {
            Lab::OpenAI => ['reasoning' => ['effort' => $effort, 'summary' => 'auto']],
            Lab::Anthropic => [
                'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
                'outputConfig' => ['effort' => $effort],
            ],
            default => [],
        };
    }

    /** One call, one Completion. Never returns an AgentResponse: it is not serializable. */
    public function ask(string $prompt): Completion
    {
        $response = $this->prompt($prompt, provider: $this->model->lab(), model: $this->model->model);

        return new Completion(
            text: $response->text,
            reasoning: $response->reasoning,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            reasoningTokens: $response->usage->reasoningTokens,
        );
    }
}
```

Die drei Unterklassen sind je vier Zeilen:

```php
<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

class ThinkAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Think;
    }
}
```

`SpeakAgent` und `SummarizeAgent` genauso, mit `Purpose::Speak` bzw. `Purpose::Summarize`.

- [ ] **Step 4: Tests laufen lassen**

Run: `php artisan test --filter=StudyAgentTest`
Expected: PASS, alle acht.

- [ ] **Step 5: Im SDK-Quellcode verifizieren, dass die Optionen ungefiltert ankommen**

Nur lesen, nichts ändern. `vendor/laravel/ai/src/Gateway/Anthropic/` und `.../OpenAi/` öffnen und
prüfen, ob die Werte aus `providerOptions()` in den Request-Body übernommen werden oder ob das
Gateway eigene Schlüssel für Thinking setzt und die unseren überschreibt. Ergebnis als Kommentar in
`StudyAgent::providerOptions()` festhalten. Überschreibt das Gateway etwas, ist das ein Fund, der
zurück in die Spec gehört, **bevor** Task 4 beginnt — dann antworten die Modelle anders als in
früheren Läufen.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint
git add app/Discussion/Agents tests/Unit/Discussion/StudyAgentTest.php
git commit -m "Add the study agents that replace the provider adapters"
```

---

### Task 4: prompt_logs aus SDK-Events

**Files:**
- Create: `app/Discussion/Logging/RecordPromptLog.php`
- Modify: `app/Providers/AppServiceProvider.php` (Listener registrieren)
- Test: `tests/Unit/Discussion/RecordPromptLogTest.php`

**Interfaces:**
- Consumes: `StudyAgent` (für `purpose()`, `jobLogId`, `expertId`, `model`), `PromptLog`
- Produces: `RecordPromptLog` mit `whenPrompting(PromptingAgent $e)`, `whenCompleted(StepCompleted $e)`,
  `whenStepFailed(StepFailed $e)`, `whenAgentFailed(AgentFailed $e)`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Logging\RecordPromptLog;
use App\Discussion\Values\ModelConfig;
use App\Models\JobLog;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Tests\TestCase;

class RecordPromptLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_step_becomes_one_row(): void
    {
        $log = JobLog::create(['job_class' => 'test', 'status' => 'running', 'started_at' => now()]);
        $agent = new SpeakAgent($this->model(), jobLogId: $log->id, expertId: 7);

        $recorder = app(RecordPromptLog::class);
        $recorder->whenPrompting($this->promptingEvent('inv-1', $agent, 'Der gerenderte Prompt'));
        $recorder->whenCompleted($this->completedEvent('inv-1', $agent, text: 'Antwort', reasoning: 'Gedanke'));

        $row = PromptLog::sole();

        $this->assertSame($log->id, $row->job_log_id);
        $this->assertSame('speak', $row->purpose);
        $this->assertSame(7, $row->expert_id);
        $this->assertSame('speak:7', $row->label);
        $this->assertSame('Der gerenderte Prompt', $row->prompt);
        $this->assertSame('Antwort', $row->response);
        $this->assertSame('Gedanke', $row->reasoning);
        $this->assertSame('ok', $row->status);
        $this->assertNull($row->error);
        $this->assertSame('probe-model', $row->model);
        $this->assertSame('probe-model-001', $row->checkpoint);
        $this->assertSame(11, $row->tokens_in);
        $this->assertSame(22, $row->tokens_out);
        $this->assertSame(3, $row->tokens_reasoning);
        $this->assertSame(42, $row->latency_ms);
        $this->assertSame('probe', $row->config['key']);
    }

    public function test_a_failed_step_is_recorded_not_dropped(): void
    {
        $agent = new SpeakAgent($this->model());

        $recorder = app(RecordPromptLog::class);
        $recorder->whenPrompting($this->promptingEvent('inv-2', $agent, 'Prompt'));
        $recorder->whenStepFailed($this->failedEvent('inv-2', $agent, new \RuntimeException('overloaded')));

        $row = PromptLog::sole();

        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('overloaded', $row->error);
        $this->assertSame('', $row->response);
        $this->assertNull($row->tokens_in);
        $this->assertSame('speak', $row->label);
    }

    public function test_the_prompt_map_does_not_leak_between_invocations(): void
    {
        $agent = new SpeakAgent($this->model());
        $recorder = app(RecordPromptLog::class);

        $recorder->whenPrompting($this->promptingEvent('inv-3', $agent, 'Erster Prompt'));
        $recorder->whenCompleted($this->completedEvent('inv-3', $agent));
        $recorder->whenCompleted($this->completedEvent('inv-3', $agent));

        $this->assertSame(1, PromptLog::count());
    }

    private function model(): ModelConfig
    {
        return new ModelConfig('probe', 'Probe', 'openai', 'probe-model', 8000, null, 'low');
    }

    private function provider(): TextProvider
    {
        return Mockery::mock(TextProvider::class, ['name' => 'openai']);
    }

    private function promptingEvent(string $id, StudyAgent $agent, string $prompt): PromptingAgent
    {
        return new PromptingAgent($id, new AgentPrompt($prompt, $this->provider(), 'probe-model', agent: $agent));
    }

    private function completedEvent(
        string $id,
        StudyAgent $agent,
        string $text = 'Antwort',
        string $reasoning = '',
    ): StepCompleted {
        $response = new StepResponse(
            text: $text,
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(11, 22, null, null, 3),
            meta: new Meta('openai', 'probe-model-001'),
            reasoning: $reasoning,
        );

        return new StepCompleted($id, 1, $agent, $this->provider(), 'probe-model', true, $response, 42.0);
    }

    private function failedEvent(string $id, StudyAgent $agent, \Throwable $e): StepFailed
    {
        return new StepFailed($id, 1, $agent, $this->provider(), 'probe-model', true, $e, 42.0);
    }
}
```

Zu `AgentPrompt`: die Basisklasse `Laravel\Ai\Prompts\Prompt` nimmt
`(string $prompt, TextProvider $provider, string $model, ?Decisions $approvalDecisions = null)`,
`AgentPrompt` ergänzt `agent`, `attachments`, `messages`, `tools`, `timeout`, `invocationId`. Die
genaue Parameterfolge des Unterklassen-Konstruktors **vor dem Schreiben des Tests** in
`vendor/laravel/ai/src/Prompts/AgentPrompt.php` nachsehen und den Aufruf oben daran anpassen; kommt
man an eine Instanz nicht heran, wird stattdessen der öffentliche Weg getestet — einen Agenten mit
`FakeAgents::always()` faken, `ask()` aufrufen und die entstandene `prompt_logs`-Zeile prüfen.

Die Konstruktoren der übrigen Events, gegen die die Helfer bauen:

- `new PromptingAgent(string $invocationId, AgentPrompt $prompt)` — `AgentPrompt` erbt von
  `Prompt(string $prompt, TextProvider $provider, string $model, ?Decisions $approvalDecisions = null)`
  und trägt zusätzlich `agent`. Wie eine Instanz am günstigsten entsteht, zeigt
  `vendor/laravel/ai/tests/` — dort wird derselbe Typ gebaut.
- `new StepCompleted(string $invocationId, int $stepNumber, Agent $agent, TextProvider $provider, string $model, bool $isFinalStep, StepResponse $response, float $time)`
  mit `new StepResponse(text: …, toolCalls: [], finishReason: FinishReason::Stop, usage: new TextUsage(11, 22, null, null, 3), meta: new Meta('openai', 'probe-model-001'), reasoning: …)`
- `new StepFailed(string $invocationId, int $stepNumber, Agent $agent, TextProvider $provider, string $model, bool $isFinalStep, Throwable $exception, float $time)`

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=RecordPromptLogTest`
Expected: FAIL — `Class "App\Discussion\Logging\RecordPromptLog" not found`.

- [ ] **Step 3: Recorder implementieren**

```php
<?php

namespace App\Discussion\Logging;

use App\Discussion\Agents\StudyAgent;
use App\Models\PromptLog;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Throwable;

/**
 * Records every model call, successful or not, in prompt_logs — the replacement
 * for LoggingLlmClient. Failures are never filtered: the study reports failure
 * rates per model.
 *
 * Our agents carry no tools, so one prompt is exactly one step and exactly one
 * row. The prompt text only exists on the PromptingAgent event, so it is held
 * per invocation until the step that consumes it finishes.
 *
 * @var array<string, string>
 */
class RecordPromptLog
{
    private array $prompts = [];

    public function whenPrompting(PromptingAgent $event): void
    {
        $this->prompts[$event->invocationId] = $event->prompt->prompt;
    }

    public function whenCompleted(StepCompleted $event): void
    {
        if (! $event->agent instanceof StudyAgent) {
            return;
        }

        $prompt = $this->take($event->invocationId);

        if ($prompt === null) {
            return;
        }

        $this->write($event->agent, $prompt, [
            'checkpoint' => $event->response->meta->model,
            'response' => $event->response->text,
            'reasoning' => $event->response->reasoning,
            'tokens_in' => $event->response->usage->inputTokens,
            'tokens_out' => $event->response->usage->outputTokens,
            'tokens_reasoning' => $event->response->usage->reasoningTokens,
            'latency_ms' => (int) $event->time,
            'status' => 'ok',
        ]);
    }

    public function whenStepFailed(StepFailed $event): void
    {
        if (! $event->agent instanceof StudyAgent) {
            return;
        }

        $prompt = $this->take($event->invocationId);

        if ($prompt === null) {
            return;
        }

        $this->write($event->agent, $prompt, [
            'response' => '',
            'latency_ms' => (int) $event->time,
            'status' => 'failed',
            'error' => $this->describe($event->exception),
        ]);
    }

    /** Net for failures that never reached a step; the map still holds the prompt. */
    public function whenAgentFailed(AgentFailed $event): void
    {
        $agent = $event->prompt->agent;

        if (! $agent instanceof StudyAgent || ! isset($this->prompts[$event->invocationId])) {
            return;
        }

        $this->write($agent, $this->take($event->invocationId), [
            'response' => '',
            'status' => 'failed',
            'error' => $this->describe($event->exception),
        ]);
    }

    private function take(string $invocationId): ?string
    {
        $prompt = $this->prompts[$invocationId] ?? null;
        unset($this->prompts[$invocationId]);

        return $prompt;
    }

    private function write(StudyAgent $agent, string $prompt, array $outcome): void
    {
        $purpose = $agent->purpose()->value;

        PromptLog::create([
            'job_log_id' => $agent->jobLogId,
            'purpose' => $purpose,
            'expert_id' => $agent->expertId,
            'label' => $agent->expertId === null ? $purpose : "{$purpose}:{$agent->expertId}",
            'provider' => $agent->model->provider,
            'model' => $agent->model->model,
            'config' => $agent->model->toArray(),
            'prompt' => $prompt,
        ] + $outcome);
    }

    private function describe(Throwable $error): string
    {
        return class_basename($error).': '.$error->getMessage();
    }
}
```

- [ ] **Step 4: Als Singleton registrieren und die vier Events verdrahten**

In `AppServiceProvider::register()`:

```php
$this->app->singleton(RecordPromptLog::class);
```

In `AppServiceProvider::boot()`:

```php
Event::listen(PromptingAgent::class, [RecordPromptLog::class, 'whenPrompting']);
Event::listen(StepCompleted::class, [RecordPromptLog::class, 'whenCompleted']);
Event::listen(StepFailed::class, [RecordPromptLog::class, 'whenStepFailed']);
Event::listen(AgentFailed::class, [RecordPromptLog::class, 'whenAgentFailed']);
```

Das Singleton ist wichtig: die Prompt-Map muss zwischen `PromptingAgent` und `StepCompleted`
dieselbe Instanz sein.

- [ ] **Step 5: Tests laufen lassen**

Run: `php artisan test --filter=RecordPromptLogTest`
Expected: PASS, alle drei.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint
git add app/Discussion/Logging app/Providers/AppServiceProvider.php tests/Unit/Discussion/RecordPromptLogTest.php
git commit -m "Record prompt_logs from the SDK step events"
```

---

### Task 5: Den Think-Pfad umstellen

Ab hier ändert sich Produktionsverhalten. Nach dieser Task laufen Think-Aufrufe über das SDK,
Speak und Summarize noch nicht.

**Files:**
- Modify: `app/Discussion/Support/Thinking.php`
- Create: `tests/Fakes/FakeAgents.php`
- Test: `tests/Unit/Discussion/ThinkAsSpeakerTest.php` (umstellen)

**Interfaces:**
- Consumes: `ThinkAgent`, `ModelConfig`, `ParallelPrompts` gibt es noch nicht — in dieser Task wird
  seriell aufgerufen, Task 8 zieht die Parallelität ein
- Produces: `Thinking::ask(TurnPayload $payload, iterable $experts, string $view, array $data = []): array<int, string>`
  — Signatur unverändert; `FakeAgents::answer(string $agentClass, string $text)` für Tests

- [ ] **Step 1: Test-Helfer schreiben**

```php
<?php

namespace Tests\Fakes;

/**
 * The SDK's Agent::fake() takes a list indexed by call order and falls back to a
 * generated placeholder once the list runs dry. Our stages parse markers out of
 * the answer, so a placeholder means a parse failure on the second turn. These
 * helpers hand back a closure instead: the same answer for every call, however
 * many turns a test runs.
 */
class FakeAgents
{
    public static function always(string $agentClass, string $text): void
    {
        $agentClass::fake(fn () => $text);
    }

    /** @param  string[]  $texts  answer n for call n; the last one repeats. */
    public static function inOrder(string $agentClass, array $texts): void
    {
        $call = 0;

        $agentClass::fake(function () use ($texts, &$call) {
            $text = $texts[$call] ?? end($texts);
            $call++;

            return $text;
        });
    }

    public static function fails(string $agentClass, \Throwable $error): void
    {
        $agentClass::fake(fn () => throw $error);
    }
}
```

- [ ] **Step 2: `ThinkAsSpeakerTest` auf die neue Verdrahtung umstellen und laufen lassen**

Das `setUp()` verliert `FakeLlmClient`/`FakeLlmFactory` und bekommt:

```php
FakeAgents::always(ThinkAgent::class, 'GEDANKE: Ich will etwas beitragen.');
```

Prompt-Prüfungen wechseln von `$this->llm->requestsFor('think')[0]->prompt` auf:

```php
ThinkAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Mein Entwurf'));
```

Run: `php artisan test --filter=ThinkAsSpeakerTest`
Expected: FAIL — `Thinking` ruft noch `LlmFactory`, der Fake greift nicht.

- [ ] **Step 3: `Thinking::ask()` umstellen**

```php
public function ask(TurnPayload $payload, iterable $experts, string $view, array $data = []): array
{
    $model = ModelConfig::fromConfig($payload->project->model);
    $shared = $data + ['project' => $payload->project] + $this->prompts->participants($payload->project);

    $answers = [];

    foreach ($experts as $expert) {
        $prompt = $this->prompts->render($view, $shared + [
            'expert' => $expert,
            'memory' => $this->memory->viewFor($payload->project, $expert),
        ]);

        $answers[$expert->id] = (new ThinkAgent($model, $payload->jobLogId, $expert->id))
            ->ask($prompt)
            ->text;
    }

    return $answers;
}
```

Der Konstruktor verliert `LlmFactory`, behält `PromptRenderer` und `Memory`. `remember()` und
`section()` bleiben unverändert.

- [ ] **Step 4: Tests laufen lassen**

Run: `php artisan test --filter="ThinkAsSpeakerTest|MemoryTest"`
Expected: PASS.

- [ ] **Step 5: Volle Suite laufen lassen und die Erwartung prüfen**

Run: `php artisan test`
Expected: `SpeakTest`, `SummarizeTest`, `TurnRunnerTest`, `PipelineSmokeTest` und
`MessageGeneratorTest` schlagen fehl, weil sie noch `FakeLlmFactory` setzen, während Think schon
über das SDK läuft. Das ist der erwartete Zwischenzustand; Tasks 6 und 7 räumen ihn auf. Kein
Commit mit roter Suite — Task 5, 6 und 7 werden als **ein** Commit am Ende von Task 7 abgeschlossen.

---

### Task 6: Speak umstellen

**Files:**
- Modify: `app/Discussion/Stages/Speak.php`
- Modify: `app/Discussion/Values/Contribution.php`
- Modify: `app/Discussion/TurnRunner.php` (eine Zeile in `measure()`)
- Create: `app/Discussion/ParseFailure.php`
- Test: `tests/Unit/Discussion/SpeakTest.php` (umstellen)

**Interfaces:**
- Consumes: `SpeakAgent`, `Completion`, `ModelConfig`
- Produces: `Contribution(string $text, ?string $partnerToken, ?string $pairType, Completion $completion)`,
  `App\Discussion\ParseFailure extends RuntimeException`

- [ ] **Step 1: `SpeakTest` umstellen**

`setUp()`: `FakeLlmClient`/`FakeLlmFactory` raus. Je Test statt `$this->llm->push('speak', $text)`:

```php
FakeAgents::always(SpeakAgent::class, $text);
```

Der Parse-Test wechselt die Exception:

```php
public function test_an_empty_visible_text_is_a_parse_failure(): void
{
    FakeAgents::always(SpeakAgent::class, "---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");

    $this->expectException(ParseFailure::class);

    $this->speak($this->payload());
}
```

Der Prompt-Test:

```php
public function test_the_prompt_carries_a_proposal_when_the_speaker_made_one(): void
{
    FakeAgents::always(SpeakAgent::class, 'Text.');

    $this->speak($this->payload(new Thought($this->alice->id, 'Gedanke', proposal: 'Mein Entwurf lautet so.')));

    SpeakAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Mein Entwurf lautet so.')
        && str_contains($prompt->prompt, Speak::MARKER_CONTROL));
}
```

Run: `php artisan test --filter=SpeakTest`
Expected: FAIL — `Speak` ruft noch `LlmFactory`.

- [ ] **Step 2: `ParseFailure` anlegen**

```php
<?php

namespace App\Discussion;

use RuntimeException;

/**
 * A stage could not read what it needs out of a model answer — a missing marker,
 * an empty contribution. Transport and API errors come from the SDK
 * (Laravel\Ai\Exceptions\*) and are not wrapped.
 */
class ParseFailure extends RuntimeException {}
```

- [ ] **Step 3: `Contribution` umstellen**

`public LlmResponse $response` wird `public Completion $completion`, Import entsprechend.

- [ ] **Step 4: `Speak` umstellen**

Konstruktor verliert `LlmFactory`, behält `PromptRenderer` und `Memory`. `handle()`:

```php
$completion = (new SpeakAgent(
    ModelConfig::fromConfig($payload->project->model),
    $payload->jobLogId,
    $speaker->id,
))->ask($this->prompt($payload, $speaker));

$payload->contribute($this->parse($completion, $payload->project));
```

`parse()` nimmt `Completion $completion` statt `LlmResponse $response`; jedes `$response->text`
darin wird `$completion->text`. Die beiden `LlmException`-Würfe werden `ParseFailure`; der
`kind`-Parameter fällt weg. `prompt()`, `partnerToken()`, `pairType()` und die Konstanten bleiben
unverändert.

- [ ] **Step 5: `TurnRunner::measure()` anpassen**

```php
'reasoning_tokens' => $contribution->completion->reasoningTokens,
```

- [ ] **Step 6: Tests laufen lassen**

Run: `php artisan test --filter="SpeakTest|TurnRunnerTest"`
Expected: `SpeakTest` PASS. `TurnRunnerTest` kann weiter fehlschlagen, solange es `FakeLlmFactory`
setzt und Summarize noch alt ist — Task 7 schließt das ab.

---

### Task 7: Summarize umstellen und den Zwischenzustand schließen

**Files:**
- Modify: `app/Discussion/Stages/Summarize.php`
- Test: `tests/Unit/Discussion/SummarizeTest.php` (umstellen)
- Test: `tests/Feature/Discussion/TurnRunnerTest.php` (umstellen)
- Test: `tests/Feature/Discussion/PipelineSmokeTest.php` (umstellen)
- Test: `tests/Feature/Jobs/MessageGeneratorTest.php` (umstellen)

**Interfaces:**
- Consumes: `SummarizeAgent`, `ModelConfig`, `FakeAgents`
- Produces: nichts Neues

- [ ] **Step 1: `Summarize` umstellen**

Konstruktor verliert `LlmFactory`. Der Aufruf wird:

```php
$completion = (new SummarizeAgent(ModelConfig::fromConfig($project->model), $payload->jobLogId))
    ->ask($this->prompts->render('prompts.summarize', [
        'project' => $project,
        'previous' => (string) $project->long_term_memory,
        'entries' => $toCompress->map(fn (Message $message) => $this->memory->describe($message))->all(),
    ]));

$project->long_term_memory = $completion->text;
```

Die Fensterlogik (`$keep`, `$batch`, `$pending`, `$toCompress`, `summarized_until_message_id`)
bleibt Zeile für Zeile, wie sie ist.

- [ ] **Step 2: Die vier Testdateien umstellen**

Überall `FakeLlmClient`/`FakeLlmFactory` entfernen. Wo heute drei Purposes gestapelt werden, treten
drei Agenten-Fakes an ihre Stelle — in `PipelineSmokeTest` ersetzt das `fakeAnswers()`:

```php
private function fakeAnswers(): void
{
    FakeAgents::always(ThinkAgent::class, 'GEDANKE: Ich will etwas beitragen.');
    FakeAgents::always(SpeakAgent::class, "Ein kurzer Beitrag.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");
    FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung.');
}
```

Der Kommentar am Kopf der Klasse wird nachgezogen: eine neue Pipeline, die andere Antworten braucht,
erweitert diese Methode. Wo Tests heute Fehler erzwingen (`failOn`), tritt
`FakeAgents::fails(SpeakAgent::class, new \RuntimeException('boom'))` an die Stelle.

- [ ] **Step 3: Volle Suite**

Run: `php artisan test`
Expected: PASS. Die Suite ist damit wieder vollständig grün — erstmals seit Task 5.

- [ ] **Step 4: Commit für die drei Tasks**

```bash
vendor/bin/pint
git add app/Discussion tests/
git commit -m "Run think, speak and summarize through the SDK agents"
```

---

### Task 8: Parallele Prompts

**Files:**
- Create: `app/Discussion/Support/ParallelPrompts.php`
- Modify: `app/Discussion/Support/Thinking.php` (die Schleife aus Task 5 ersetzen)
- Modify: `phpunit.xml`
- Test: `tests/Unit/Discussion/ParallelPromptsTest.php`

**Interfaces:**
- Consumes: `Completion`
- Produces: `ParallelPrompts::run(array $tasks): array` mit `array<key, Closure(): Completion>` hinein
  und `array<key, Completion>` heraus, Schlüssel erhalten

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\ParallelPrompts;
use App\Discussion\Values\Completion;
use Tests\TestCase;

class ParallelPromptsTest extends TestCase
{
    private function completion(string $text): Completion
    {
        return new Completion($text, '', 1, 1, null);
    }

    public function test_keys_survive_the_round_trip(): void
    {
        $result = app(ParallelPrompts::class)->run([
            7 => fn () => $this->completion('sieben'),
            9 => fn () => $this->completion('neun'),
        ]);

        $this->assertSame([7, 9], array_keys($result));
        $this->assertSame('sieben', $result[7]->text);
        $this->assertSame('neun', $result[9]->text);
    }

    public function test_a_single_task_takes_the_direct_path(): void
    {
        $result = app(ParallelPrompts::class)->run([3 => fn () => $this->completion('drei')]);

        $this->assertSame('drei', $result[3]->text);
    }

    public function test_an_empty_list_is_no_work(): void
    {
        $this->assertSame([], app(ParallelPrompts::class)->run([]));
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `php artisan test --filter=ParallelPromptsTest`
Expected: FAIL — `Class "App\Discussion\Support\ParallelPrompts" not found`.

- [ ] **Step 3: Implementieren**

```php
<?php

namespace App\Discussion\Support;

use Closure;
use Illuminate\Support\Facades\Concurrency;

/**
 * Runs several prompts at once. The SDK has no batch API — prompt() is
 * synchronous — so this stays our own code, built the way the provider adapters
 * did it before: nothing but value objects crosses the process boundary, and
 * each task builds its own agent inside the child process.
 *
 * Tests must run the sync concurrency driver (see phpunit.xml): a child process
 * sees neither the in-memory SQLite nor the faked agents.
 */
class ParallelPrompts
{
    /**
     * @param  array<array-key, Closure(): \App\Discussion\Values\Completion>  $tasks
     * @return array<array-key, \App\Discussion\Values\Completion>
     */
    public function run(array $tasks): array
    {
        if (count($tasks) <= 1) {
            return array_map(fn (Closure $task) => $task(), $tasks);
        }

        $keys = array_keys($tasks);

        // Concurrency::run returns a list; restore the caller's keys.
        return array_combine($keys, array_values(Concurrency::run(array_values($tasks))));
    }
}
```

- [ ] **Step 4: Test laufen lassen**

Run: `php artisan test --filter=ParallelPromptsTest`
Expected: PASS.

- [ ] **Step 5: Testumgebung auf den sync-Treiber festlegen**

In `phpunit.xml` zwischen die anderen `<env>`-Zeilen:

```xml
<env name="CONCURRENCY_DRIVER" value="sync"/>
```

- [ ] **Step 6: `Thinking::ask()` auf ParallelPrompts umstellen**

```php
public function ask(TurnPayload $payload, iterable $experts, string $view, array $data = []): array
{
    $modelKey = $payload->project->model;
    $jobLogId = $payload->jobLogId;
    $shared = $data + ['project' => $payload->project] + $this->prompts->participants($payload->project);

    $tasks = [];

    foreach ($experts as $expert) {
        $prompt = $this->prompts->render($view, $shared + [
            'expert' => $expert,
            'memory' => $this->memory->viewFor($payload->project, $expert),
        ]);

        $expertId = $expert->id;

        // Only scalars are captured: the agent is built inside the child process.
        $tasks[$expertId] = fn () => (new ThinkAgent(ModelConfig::fromConfig($modelKey), $jobLogId, $expertId))
            ->ask($prompt);
    }

    return array_map(fn (Completion $completion) => $completion->text, $this->parallel->run($tasks));
}
```

`ParallelPrompts` wird als `private readonly ParallelPrompts $parallel` in den Konstruktor
aufgenommen.

- [ ] **Step 7: Volle Suite und Commit**

```bash
php artisan test
vendor/bin/pint
git add app/Discussion/Support phpunit.xml tests/Unit/Discussion/ParallelPromptsTest.php
git commit -m "Prompt several agents at once through Laravel's Concurrency"
```

---

### Task 9: Die alte Schicht löschen

**Files:**
- Delete: `app/Llm/` (vollständig)
- Delete: `tests/Fakes/FakeLlmClient.php`, `tests/Fakes/FakeLlmFactory.php`, `tests/Unit/Llm/`
- Create: `app/Discussion/Logging/GuardAgainstFailover.php`
- Modify: `config/llm.php`, `composer.json`, `app/Providers/AppServiceProvider.php`
  (**Korrektur nach Umsetzung:** `config/llm.php` wurde gelöscht, nicht geändert — siehe Step 3)
- Test: `tests/Unit/Discussion/GuardAgainstFailoverTest.php`

**Interfaces:**
- Consumes: SDK-Events `ProviderFailedOver`, `AgentFailedOver`
- Produces: `GuardAgainstFailover::handle(object $event): void`

- [ ] **Step 1: Prüfen, dass niemand mehr auf `App\Llm` zeigt**

```bash
grep -rn "App\\\\Llm" app tests config database resources routes || echo "clean"
```

Expected: `clean`. Jeder Treffer muss vorher verschwinden — es darf keinen Import mehr geben.

- [ ] **Step 2: Löschen**

```bash
git rm -r app/Llm tests/Unit/Llm tests/Fakes/FakeLlmClient.php tests/Fakes/FakeLlmFactory.php
```

- [ ] **Step 3: `config/llm.php` entschlacken**

`providers` und `keys` entfernen — Adapter-Klassen und Zugangsdaten sind jetzt Sache von
`config/ai.php`. `default` und `models` bleiben, inklusive der Kommentare zur Bedeutung von
`temperature: null` und dem fehlenden Effort-Mapping bei Gemini. Der Kopfkommentar wird
umgeschrieben: die Datei ist die Studien-Registry der wählbaren Modelle, nicht mehr die
Anbieter-Konfiguration.

> **Korrektur (nach Umsetzung):** So wurde es nicht gebaut. Der Nutzer hat entschieden,
> `config/llm.php` nicht zu verschlanken, sondern ganz aufzulösen: `models` und `default_model`
> (vormals `default`) sind als eigener, kommentierter Block in `config/ai.php` gelandet, direkt
> neben den SDK-eigenen Schlüsseln `default` (Standard-*Provider*, nicht zu verwechseln mit unserem
> `default_model`) und `providers` (Zugangsdaten). `config/llm.php` existiert nach Task 9 nicht
> mehr. `ModelConfig::fromConfig()` liest entsprechend aus `config('ai.models.*')`.

- [ ] **Step 4: Alte Anbieter-SDKs aus composer.json entfernen**

```bash
composer remove anthropic-ai/sdk openai-php/client
```

`guzzlehttp/guzzle` bleibt — Laravel zieht es ohnehin.

- [ ] **Step 5: Failover-Wächter mit Test**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Logging\GuardAgainstFailover;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GuardAgainstFailoverTest extends TestCase
{
    public function test_a_failover_is_logged_as_an_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'failover'));

        app(GuardAgainstFailover::class)->handle(new \stdClass);
    }
}
```

```php
<?php

namespace App\Discussion\Logging;

use Illuminate\Support\Facades\Log;

/**
 * The SDK can silently switch providers when a call fails. For this study that
 * would break a run: the model family per run must stay homogeneous, and a
 * silent switch would not show up anywhere in the measurement. Nothing here
 * configures failover — this only makes noise if it ever happens.
 */
class GuardAgainstFailover
{
    public function handle(object $event): void
    {
        Log::error('An AI provider failover happened; this run is no longer homogeneous. Event: '.$event::class);
    }
}
```

Registrierung in `AppServiceProvider::boot()`:

```php
Event::listen([ProviderFailedOver::class, AgentFailedOver::class], [GuardAgainstFailover::class, 'handle']);
```

- [ ] **Step 6: Volle Suite**

Run: `php artisan test`
Expected: PASS. Die Zahl der Tests sinkt um die gelöschten LLM-Adapter-Tests und steigt um die neuen
aus Tasks 1–8.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint
git add -A
git commit -m "Delete the hand-written LLM layer and guard against provider failover"
```

---

### Task 10: Dokumentation nachziehen

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/pipeline.puml`

**Interfaces:**
- Consumes: den Endzustand aus Tasks 1–9
- Produces: nichts Ausführbares

- [ ] **Step 1: `CLAUDE.md`, Abschnitt „LLM layer" neu schreiben**

Ersetzt den bisherigen Absatz vollständig. Inhalt: `laravel/ai` ist die LLM-Schicht; eine
Agent-Klasse je Zweck unter `App\Discussion\Agents` mit `StudyAgent` als Basis; `ask()` gibt ein
`Completion`; `RecordPromptLog` schreibt jeden Aufruf inklusive Fehlschlag nach `prompt_logs`;
`config/llm.php` ist die Studien-Registry der wählbaren Modelle, `config/ai.php` hält die
Zugangsdaten; ein neuer Anbieter ist eine Zeile in `config/llm.php`, keine Klasse.

Dazu die zwei Verbote als eigener Absatz, mit Begründung: kein Failover (sonst wird die
Modellfamilie eines Runs inhomogen, ohne im Log aufzufallen), kein `RemembersConversations` (sonst
steht ein zweites Gedächtnis neben den drei Memory-Schichten). Und der Hinweis, dass das SDK keine
parallelen Aufrufe kennt, `ParallelPrompts` deshalb eigener Code ist und die Testumgebung den
`sync`-Treiber braucht.

- [ ] **Step 2: Die sieben offenen Verbesserungen an `CLAUDE.md` mit eintragen**

1. Commands ergänzen: `php artisan migrate`, `php artisan auth:create-user`,
   `php artisan auth:create-users {count}`, `php artisan test --testsuite=Unit`.
2. `Support\Thinking` in der Stage-Beschreibung nennen: pro Expert rendern, parallel fragen,
   `remember()` schreibt das Short-Term-Memory, `section()` schneidet an Markern.
3. Parallelität und ihre Betriebsbedingung (Kindprozesse, `sync` in Tests) — deckt sich mit Step 1.
4. `projects.seed` wird erzeugt, aber nirgends gelesen → unter „Still open" aufnehmen: zufällige
   Tie-Breaks sind derzeit nicht reproduzierbar.
5. Seat-Semantik unter „Data model notes": Sitze kommen aus `project_contributors.seat`, werden als
   `max+1` vergeben und nach einem Entfernen nicht neu durchnummeriert — relevant, weil die
   Statusrotation über Sitze laufen soll.
6. Autorisierung: die vier Gates in `AuthServiceProvider::register()` (`access-project`,
   `manage-project`, `manage-contributors`, `admin`), der Middleware-Alias `admin`, und der zweite
   Broadcast-Channel `debug`.
7. Doku-Landkarte: `docs/archify/` (architektur, turn-datenfluss, ziel-architektur, ziel-pipelines),
   die Spec und der Plan unter `docs/superpowers/`, und dass `README.md` ein Stub ist.

- [ ] **Step 3: `docs/pipeline.puml` nachziehen**

Nur die Kanten zum Modell: aus `LlmFactory`/`LoggingLlmClient` wird der jeweilige Agent plus
`RecordPromptLog` als Abnehmer der Step-Events. Die Stage-Kette bleibt unverändert.

- [ ] **Step 4: Abschließend die volle Suite und der Stil-Check**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, keine Stilabweichung.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/pipeline.puml
git commit -m "Document the SDK-based LLM layer"
```

---

## Abschluss-Prüfung gegen die Akzeptanzkriterien der Spec

- [ ] `app/Llm` existiert nicht mehr: `test ! -d app/Llm && echo ok`
- [ ] `grep -c "anthropic-ai/sdk\|openai-php/client" composer.json` ergibt 0
- [ ] Ein Turn schreibt dieselben Zeilen wie vorher: in `tinker` ein Demo-Projekt aus
      `php artisan dev:build-suite` einen Turn laufen lassen und `prompt_logs` sowie `job_logs` mit
      einer vor dem Umstieg erzeugten Zeile vergleichen — gleiche Spalten belegt, `purpose` und
      `label` zeichengleich
- [ ] `projects.run_config` hat nach dem ersten Turn dieselben Schlüssel wie vorher
- [ ] `php artisan test` grün, `vendor/bin/pint --test` ohne Befund
- [ ] Ein zweiter Anbieter ist eine Zeile in `config/llm.php`: gegenprüfen, indem ein vierter
      Modell-Eintrag (z. B. `groq`) hinzugefügt, `ModelRegistryTest` laufen gelassen und der Eintrag
      wieder entfernt wird
