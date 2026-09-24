# Die drei fehlenden Sprecherwahl-Pipelines: Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Studie kann alle vier Sprecherwahl-Mechanismen fahren, nicht nur Round Robin.

**Architecture:** Drei neue `SpeakerSelector`-Implementierungen an dem Seam, den die Studie variiert, dazu zwei Think-Stages, die alle Agenten parallel fragen, ein deterministischer `TieBreaker` und zwei Agent-Klassen für die Modellaufrufe der Selektoren. Jede Zelle ist eine Pipeline-Klasse mit zwölf Zeilen; die Registry findet sie ohne Registrierung.

**Tech Stack:** Laravel 12, PHP 8.3+, `laravel/ai` 1.0, PHPUnit 11, SQLite in-memory für Tests.

**Spec:** `docs/superpowers/specs/2026-09-24-drei-sprecherwahl-pipelines-design.md`

## Global Constraints

- **Kein Selector gleicht Beteiligung aus.** Keine Obergrenze für Wiederholungen, keine Bevorzugung seltener Sprecher. Derselbe Agent darf beliebig oft hintereinander drankommen — das ist das Messergebnis, nicht ein Fehler.
- **Kein unlesbares Modellergebnis beendet einen Lauf.** Fallback plus Protokoll nach der Tabelle in Abschnitt 4.8 der Spec. Ausnahme: ein fehlender `GEDANKE:` bleibt ein `ParseFailure`.
- **Keine Anweisungen an den Sprecher.** Der Orchestrator wählt nur; er gibt keine Rolle, keine Agenda und keinen Konvergenzzug mit.
- **Marker in Blade-Prompts, kein `HasStructuredOutput`.** Strukturierte Ausgabe setzt jeder Anbieter anders um und wäre bei drei Modellfamilien eine Störvariable.
- **Keine Migration.** Alle Signale gehen in `job_logs.selection`, das `Selection::toArray()` schon schreibt.
- **`ask()` ist der einzige Weg zum Modell.** `Promptable::prompt()` würde `config('ai.default')` nehmen statt des Projektmodells.
- **`ModelConfig::toArray()` bleibt zeichengleich** — Format von `projects.run_config.model` und `prompt_logs.config`.
- **`Purpose`-Werte bleiben zeichengleich:** `think`, `select`, `speak`, `summarize`; `judge` kommt additiv dazu.
- **Commits pfadgebunden** (`git commit -m … -- <pfade>`): der Nutzer arbeitet im selben Repository. `.idea/*` bleibt draußen.
- **Pint nur über die berührten Dateien** — ein repo-weiter Lauf formatiert ~28 fremde Dateien um.
- Nach jeder Task: `php artisan test` grün, ein Commit.

---

## Dateiübersicht

Neu:

| Datei | Verantwortung |
|---|---|
| `app/Discussion/Support/TieBreaker.php` | Gleichstände deterministisch aus `projects.seed` und `turnIndex` auflösen |
| `app/Discussion/Agents/SelectorAgent.php` | Modellaufruf des Orchestrators (`Purpose::Select`) |
| `app/Discussion/Agents/JudgeAgent.php` | Modellaufruf des Judge (`Purpose::Judge`) |
| `app/Discussion/Stages/ThinkAndPrioritize.php` | alle denken, jeder bietet eine Priorität |
| `app/Discussion/Stages/ThinkAndPropose.php` | alle denken, jeder entwirft einen Beitrag |
| `app/Discussion/Selectors/HighestBidSelector.php` | höchstes Gebot gewinnt, ohne Modellaufruf |
| `app/Discussion/Selectors/OrchestratorSelector.php` | ein Modellaufruf wählt aus dem Verlauf |
| `app/Discussion/Selectors/JudgeSelector.php` | ein Modellaufruf bewertet die Entwürfe |
| `app/Discussion/Pipelines/ScorePipeline.php` | Zelle 2 der Matrix |
| `app/Discussion/Pipelines/OrchestratorPipeline.php` | Zelle 3 |
| `app/Discussion/Pipelines/QualityPipeline.php` | Zelle 4 |
| `resources/views/prompts/think/prioritize.blade.php` | Gedanke plus Gebot |
| `resources/views/prompts/think/propose.blade.php` | Gedanke plus Entwurf |
| `resources/views/prompts/select/orchestrator.blade.php` | Sprecherwahl aus dem Verlauf |
| `resources/views/prompts/select/judge.blade.php` | Bewertung der Entwürfe |

Geändert: `app/Discussion/Purpose.php`, `app/Discussion/Agents/StudyAgent.php`, `app/Discussion/Support/Thinking.php`, `app/Discussion/Stages/ThinkAsSpeaker.php`, `lang/{de,en}/pipelines.php`, `tests/Feature/Discussion/PipelineSmokeTest.php`, `CLAUDE.md`, `docs/pipeline.puml`.

## Parallelisierung

Die Tasks laufen in vier Wellen. Innerhalb einer Welle berühren die Tasks **disjunkte Dateien** und dürfen gleichzeitig bearbeitet werden; über Wellengrenzen hinweg nicht.

| Welle | Tasks | Warum parallel möglich |
|---|---|---|
| A | 1, 2 | `Support/TieBreaker.php` gegen `Purpose.php` + `Agents/*` — kein gemeinsamer Pfad |
| B | 3 | zieht alle geteilten Dateien vor: `Thinking`, `ThinkAsSpeaker`, die drei Labels, `PipelineSmokeTest` |
| C | 4, 5, 6 | je eine Zelle mit eigener Stage, eigenem Selector, eigener Pipeline, eigenem View und eigenen Tests |
| D | 7 | Doku, nach allem |

Welle B existiert nur, damit Welle C konfliktfrei ist: ohne sie würden alle drei Zellen-Tasks `PipelineSmokeTest::fakeAnswers()` und `lang/*/pipelines.php` anfassen.

---

### Task 1: TieBreaker

**Files:**
- Create: `app/Discussion/Support/TieBreaker.php`
- Test: `tests/Unit/Discussion/TieBreakerTest.php`

**Interfaces:**
- Consumes: `Project` (für `seed`), `Expert`
- Produces: `TieBreaker::pick(array $candidates, Project $project, int $turnIndex): Expert` — `$candidates` ist eine Liste von `Expert`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\TieBreaker;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TieBreakerTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Expert> */
    private function experts(int $count): array
    {
        return Expert::factory()->count($count)->create()->all();
    }

    public function test_a_single_candidate_needs_no_draw(): void
    {
        $experts = $this->experts(1);
        $project = Project::factory()->create(['seed' => 12345]);

        $this->assertSame($experts[0]->id, app(TieBreaker::class)->pick($experts, $project, 1)->id);
    }

    public function test_the_same_seed_and_turn_always_pick_the_same_candidate(): void
    {
        $experts = $this->experts(4);
        $project = Project::factory()->create(['seed' => 12345]);
        $breaker = app(TieBreaker::class);

        $first = $breaker->pick($experts, $project, 7)->id;

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $breaker->pick($experts, $project, 7)->id);
        }
    }

    public function test_the_order_of_the_candidate_list_does_not_change_the_outcome(): void
    {
        $experts = $this->experts(4);
        $project = Project::factory()->create(['seed' => 999]);
        $breaker = app(TieBreaker::class);

        $shuffled = $experts;
        $shuffled = array_reverse($shuffled);

        $this->assertSame(
            $breaker->pick($experts, $project, 3)->id,
            $breaker->pick($shuffled, $project, 3)->id,
        );
    }

    public function test_a_different_seed_or_turn_can_pick_someone_else(): void
    {
        $experts = $this->experts(4);
        $breaker = app(TieBreaker::class);
        $project = Project::factory()->create(['seed' => 12345]);

        // Over many turns the draw must not stick to one candidate.
        $picked = [];
        for ($turn = 1; $turn <= 40; $turn++) {
            $picked[$breaker->pick($experts, $project, $turn)->id] = true;
        }

        $this->assertGreaterThan(1, count($picked), 'The draw always returns the same candidate.');
    }

    public function test_every_candidate_comes_up_over_enough_turns(): void
    {
        $experts = $this->experts(4);
        $project = Project::factory()->create(['seed' => 4711]);
        $breaker = app(TieBreaker::class);

        $picked = [];
        for ($turn = 1; $turn <= 400; $turn++) {
            $picked[$breaker->pick($experts, $project, $turn)->id] = true;
        }

        $this->assertCount(4, $picked, 'Some candidate is never drawn.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TieBreakerTest`
Expected: FAIL with `Class "App\Discussion\Support\TieBreaker" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Discussion\Support;

use App\Models\Expert;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Resolves a tie without favouring anyone. Deterministic on purpose: the draw
 * comes from the project's seed and the turn index, so a rerun of the same run
 * makes the same choices — projects.seed exists for exactly this.
 *
 * No RNG state is touched (no mt_srand): a global seed would leak into whatever
 * else draws random numbers in the same process.
 */
class TieBreaker
{
    /** @param  list<Expert>  $candidates */
    public function pick(array $candidates, Project $project, int $turnIndex): Expert
    {
        if ($candidates === []) {
            throw new InvalidArgumentException('TieBreaker needs at least one candidate.');
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Sorted by id so the caller's array order cannot influence the outcome.
        usort($candidates, fn (Expert $a, Expert $b) => $a->id <=> $b->id);

        $draw = crc32($project->seed.':'.$turnIndex);

        return $candidates[$draw % count($candidates)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TieBreakerTest`
Expected: PASS, all five.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app/Discussion/Support/TieBreaker.php tests/Unit/Discussion/TieBreakerTest.php
git commit -m "Add the deterministic tie breaker" -- app/Discussion/Support/TieBreaker.php tests/Unit/Discussion/TieBreakerTest.php
```

---

### Task 2: Die zwei Selektor-Agenten und der Riegel an prompt()

**Files:**
- Modify: `app/Discussion/Purpose.php`
- Create: `app/Discussion/Agents/SelectorAgent.php`
- Create: `app/Discussion/Agents/JudgeAgent.php`
- Modify: `app/Discussion/Agents/StudyAgent.php` (nur `prompt()` dazu)
- Test: `tests/Unit/Discussion/StudyAgentTest.php` (erweitern)

**Interfaces:**
- Consumes: `ModelConfig`, `Purpose`, `StudyAgent`
- Produces: `Purpose::Judge` (Wert `'judge'`), `SelectorAgent`, `JudgeAgent`, und ein `StudyAgent::prompt()`, das bei einem fremden Provider wirft

- [ ] **Step 1: Write the failing tests**

An `tests/Unit/Discussion/StudyAgentTest.php` anhängen (die vorhandene `model()`-Hilfsmethode der Klasse mitbenutzen):

```php
    public function test_the_selector_and_judge_agents_carry_their_own_purpose(): void
    {
        $this->assertSame(Purpose::Select, (new SelectorAgent($this->model('openai')))->purpose());
        $this->assertSame(Purpose::Judge, (new JudgeAgent($this->model('openai')))->purpose());
    }

    public function test_the_judge_purpose_is_stored_as_judge(): void
    {
        $this->assertSame('judge', Purpose::Judge->value);
    }

    public function test_prompting_with_a_foreign_provider_is_refused(): void
    {
        $agent = new SelectorAgent($this->model('openai'));

        $this->expectException(InvalidArgumentException::class);

        // Promptable::prompt() is public. Called directly with someone else's lab
        // it would silently use that provider instead of the project's model.
        $agent->prompt('Wer spricht?', provider: Lab::Anthropic, model: 'claude-opus-5');
    }

    public function test_prompting_without_a_provider_is_refused(): void
    {
        $agent = new SelectorAgent($this->model('openai'));

        $this->expectException(InvalidArgumentException::class);

        // No provider means config('ai.default') — the study's model choice bypassed.
        $agent->prompt('Wer spricht?');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StudyAgentTest`
Expected: FAIL with `Class "App\Discussion\Agents\SelectorAgent" not found`.

- [ ] **Step 3: Purpose erweitern**

```php
    case Judge = 'judge';
```

Als vierter Fall hinter `Summarize`. Die vorhandenen vier Werte bleiben unberührt — sie stehen in `prompt_logs.purpose`.

- [ ] **Step 4: Die zwei Agenten anlegen**

```php
<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

/** Picks the next speaker from the visible history. Sees no private thoughts. */
class SelectorAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Select;
    }
}
```

```php
<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;

/** Scores the agents' drafts. Its own purpose so judge calls are filterable in prompt_logs. */
class JudgeAgent extends StudyAgent
{
    public function purpose(): Purpose
    {
        return Purpose::Judge;
    }
}
```

- [ ] **Step 5: Den Riegel in StudyAgent einbauen**

In `StudyAgent`, neben `ask()`:

```php
    /**
     * Promptable::prompt() is public, and a caller that reaches for it directly
     * gets config('ai.default') instead of this project's model: a run with mixed
     * model families, invisible in job_logs, in prompt_logs.provider and to the
     * failover guard. ask() is the way in; this override makes any other route
     * fail loudly instead.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): AgentResponse
    {
        if ($provider !== $this->model->lab() || $model !== $this->model->model) {
            throw new InvalidArgumentException(sprintf(
                'Prompt %s with model key [%s] through ask(): prompt() was called with provider [%s] and model [%s].',
                static::class,
                $this->model->key,
                is_object($provider) ? $provider->value : var_export($provider, true),
                var_export($model, true),
            ));
        }

        return parent::prompt($prompt, $attachments, $provider, $model, $timeout);
    }
```

Die Signatur muss der des Traits entsprechen — vor dem Schreiben in `vendor/laravel/ai/src/Promptable.php` nachsehen und Parameterfolge und Typen genau übernehmen; weicht sie ab, die hier gezeigte anpassen und den Unterschied im Report nennen. Die Importe (`AgentInput`, `UserMessage`, `Decisions`, `AgentResponse`, `InvalidArgumentException`) ergänzen.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=StudyAgentTest`
Expected: PASS. Achtung: `StudyAgentTest` doubelt in drei bestehenden Tests `prompt()` über eine anonyme Unterklasse — diese Doubles überschreiben die Methode und umgehen den Riegel, das ist gewollt und muss weiter laufen.

- [ ] **Step 7: Volle Suite und Commit**

```bash
php artisan test
vendor/bin/pint app/Discussion/Purpose.php app/Discussion/Agents tests/Unit/Discussion/StudyAgentTest.php
git commit -m "Add the selector and judge agents, and bar the way around ask()" -- app/Discussion/Purpose.php app/Discussion/Agents tests/Unit/Discussion/StudyAgentTest.php
```

---

### Task 3: Die geteilten Dateien vorziehen

Diese Task berührt alles, was sich die drei Zellen sonst teilen würden. Nach ihr sind Tasks 4 bis 6 konfliktfrei.

**Files:**
- Modify: `app/Discussion/Support/Thinking.php` (`MARKER_THOUGHT` dazu)
- Modify: `app/Discussion/Stages/ThinkAsSpeaker.php` (Konstante entfernen, Thinkings benutzen)
- Modify: `lang/de/pipelines.php`, `lang/en/pipelines.php` (drei Labels)
- Modify: `tests/Feature/Discussion/PipelineSmokeTest.php` (`fakeAnswers()` final)
- Test: `tests/Unit/Discussion/ThinkAsSpeakerTest.php` (Konstantenbezug nachziehen)

**Interfaces:**
- Consumes: `SelectorAgent`, `JudgeAgent` aus Task 2
- Produces: `Thinking::MARKER_THOUGHT` (`'GEDANKE:'`), Labels für `ScorePipeline`, `OrchestratorPipeline`, `QualityPipeline`, und ein `fakeAnswers()`, das alle fünf Agent-Klassen abdeckt

- [ ] **Step 1: `MARKER_THOUGHT` nach `Thinking` ziehen**

In `Thinking`, über `ask()`:

```php
    /** Every Think variant asks for the rolling thought under this marker. */
    public const MARKER_THOUGHT = 'GEDANKE:';
```

In `ThinkAsSpeaker` die eigene Konstante entfernen und alle drei Vorkommen (`self::MARKER_THOUGHT` im `ask()`-Datenarray, in `Thinking::section()` und in der Fehlermeldung) auf `Thinking::MARKER_THOUGHT` umstellen. Grund: nach Task 4 und 6 fragen drei Stages nach demselben Marker; er darf nicht dreimal definiert sein.

- [ ] **Step 2: Bezug im Test nachziehen**

`tests/Unit/Discussion/ThinkAsSpeakerTest.php` verweist auf `ThinkAsSpeaker::MARKER_THOUGHT` — auf `Thinking::MARKER_THOUGHT` ändern, Import ergänzen.

Run: `php artisan test --filter=ThinkAsSpeakerTest`
Expected: PASS.

- [ ] **Step 3: Die drei Labels anlegen**

`lang/de/pipelines.php`:

```php
    'ScorePipeline' => 'Gebote (jeder Agent bietet 1–5)',
    'OrchestratorPipeline' => 'Orchestrator (ein Modell wählt)',
    'QualityPipeline' => 'Entwurfsqualität (ein Judge bewertet)',
```

`lang/en/pipelines.php`:

```php
    'ScorePipeline' => 'Bidding (each agent bids 1–5)',
    'OrchestratorPipeline' => 'Orchestrator (a model picks)',
    'QualityPipeline' => 'Proposal quality (a judge scores)',
```

Labels für Klassen, die es noch nicht gibt, sind harmlos: die Oberfläche listet nur, was `PipelineRegistry` im Ordner findet.

- [ ] **Step 4: `fakeAnswers()` final machen**

In `tests/Feature/Discussion/PipelineSmokeTest.php`:

```php
    private function fakeAnswers(): void
    {
        // One answer per agent class, carrying every marker any stage parses: the
        // think stages each read their own section out of it, so this covers the
        // speaker, the bidding and the proposing variant alike.
        FakeAgents::always(ThinkAgent::class, implode("\n", [
            'GEDANKE: Ich will etwas beitragen.',
            'PRIORITÄT: 3',
            'ENTWURF: Ein kurzer Entwurf.',
        ]));
        FakeAgents::always(SpeakAgent::class, "Ein kurzer Beitrag.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");
        FakeAgents::always(SummarizeAgent::class, 'Zusammenfassung.');
        FakeAgents::always(SelectorAgent::class, "SPRECHER: E1\nBEGRÜNDUNG: Weil E1 noch nicht dran war.");
        FakeAgents::always(JudgeAgent::class, "BEWERTUNG: E1 7\nBEGRÜNDUNG: Der Entwurf ist konkret.");
    }
```

Die Importe für `SelectorAgent` und `JudgeAgent` ergänzen. Der Klassenkommentar wird nachgezogen: eine neue Pipeline ist abgedeckt, solange sie diese fünf Agenten benutzt; braucht sie einen weiteren, kommt er hier dazu.

**Wichtig zum `SPRECHER: E1`:** Die Antwort nennt ein festes Token, das im Test nicht existieren muss — `OrchestratorSelector` fällt dann auf den TieBreaker zurück und protokolliert das. Für den Smoke-Test ist beides ein Erfolg: er prüft, dass ein Turn durchläuft, nicht wen er wählt.

- [ ] **Step 5: Volle Suite und Commit**

```bash
php artisan test
vendor/bin/pint app/Discussion/Support/Thinking.php app/Discussion/Stages/ThinkAsSpeaker.php tests
git commit -m "Move the thought marker to Thinking and prepare the shared test fakes" -- app/Discussion/Support/Thinking.php app/Discussion/Stages/ThinkAsSpeaker.php lang/de/pipelines.php lang/en/pipelines.php tests/Feature/Discussion/PipelineSmokeTest.php tests/Unit/Discussion/ThinkAsSpeakerTest.php
```

---

### Task 4: Die Gebots-Zelle

**Files:**
- Create: `app/Discussion/Stages/ThinkAndPrioritize.php`
- Create: `resources/views/prompts/think/prioritize.blade.php`
- Create: `app/Discussion/Selectors/HighestBidSelector.php`
- Create: `app/Discussion/Pipelines/ScorePipeline.php`
- Test: `tests/Unit/Discussion/ThinkAndPrioritizeTest.php`
- Test: `tests/Unit/Discussion/HighestBidSelectorTest.php`

**Interfaces:**
- Consumes: `Thinking` samt `MARKER_THOUGHT`, `TieBreaker`, `Thought`, `Selection`, `FakeAgents`
- Produces: `ThinkAndPrioritize::MARKER_PRIORITY` (`'PRIORITÄT:'`), `HighestBidSelector`, `ScorePipeline`

- [ ] **Step 1: Failing test für die Stage**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\ThinkAndPrioritize;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ThinkAndPrioritizeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->project = Project::factory()->create();
        foreach (Expert::factory()->count(3)->create() as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    // Not run(): TestCase::run() is final and cannot be overridden.
    private function prioritize(): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1);

        return app(ThinkAndPrioritize::class)->handle($payload, fn (TurnPayload $p) => $p);
    }

    public function test_every_contributing_expert_bids(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nPRIORITÄT: 4");

        $payload = $this->prioritize();

        $this->assertCount(3, $payload->thoughts());

        foreach ($this->project->contributingExperts() as $expert) {
            $thought = $payload->thoughtOf($expert);
            $this->assertNotNull($thought);
            $this->assertSame('Mein Gedanke.', $thought->text);
            $this->assertSame(4, $thought->priority);
        }
    }

    public function test_the_short_term_memory_of_every_agent_is_written(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nPRIORITÄT: 2");

        $this->prioritize();

        $this->assertSame(3, Summary::where('project_id', $this->project->id)->count());
    }

    public function test_an_unreadable_priority_becomes_null_for_the_selector_to_handle(): void
    {
        FakeAgents::always(ThinkAgent::class, 'GEDANKE: Mein Gedanke.');

        $payload = $this->prioritize();

        $this->assertNull($payload->thoughtOf($this->project->contributingExperts()->first())->priority);
    }

    public function test_a_priority_outside_one_to_five_counts_as_unreadable(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nPRIORITÄT: 9");

        $payload = $this->prioritize();

        $this->assertNull($payload->thoughtOf($this->project->contributingExperts()->first())->priority);
    }

    public function test_a_missing_thought_marker_is_a_parse_failure(): void
    {
        FakeAgents::always(ThinkAgent::class, 'PRIORITÄT: 3');

        $this->expectException(ParseFailure::class);

        $this->prioritize();
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --filter=ThinkAndPrioritizeTest`
Expected: FAIL with `Class "App\Discussion\Stages\ThinkAndPrioritize" not found`.

- [ ] **Step 3: Stage und View anlegen**

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use Closure;

/** Everyone thinks and bids for the floor. Belongs before SelectSpeaker(HighestBid). */
class ThinkAndPrioritize
{
    public const MARKER_PRIORITY = 'PRIORITÄT:';

    private const LOWEST = 1;

    private const HIGHEST = 5;

    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.prioritize', [
            'marker_thought' => Thinking::MARKER_THOUGHT,
            'marker_priority' => self::MARKER_PRIORITY,
            'lowest' => self::LOWEST,
            'highest' => self::HIGHEST,
        ]);

        foreach ($experts as $expert) {
            $answer = $answers[$expert->id];
            $thought = Thinking::section($answer, Thinking::MARKER_THOUGHT, self::MARKER_PRIORITY);

            if ($thought === '') {
                throw new ParseFailure(
                    "ThinkAndPrioritize: marker '".Thinking::MARKER_THOUGHT."' missing in the answer of expert {$expert->id}."
                );
            }

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought, priority: $this->priority($answer)));
        }

        return $next($payload);
    }

    /**
     * null when the bid is missing or out of range. The stage does not substitute a
     * value: HighestBidSelector owns the fallback and records it in the signals, so
     * the study can tell a real bid from a replaced one.
     */
    private function priority(string $answer): ?int
    {
        $raw = trim(Thinking::section($answer, self::MARKER_PRIORITY));

        if (! preg_match('/^\d+/', $raw, $match)) {
            return null;
        }

        $value = (int) $match[0];

        return $value >= self::LOWEST && $value <= self::HIGHEST ? $value : null;
    }
}
```

`resources/views/prompts/think/prioritize.blade.php` — nach dem Muster von `think/speaker.blade.php`, das zuerst gelesen werden sollte (Persona, Projekt, Teilnehmer, Memory als Includes, dann die Aufgabe, am Ende das Pflichtformat):

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
Alle Beteiligten überlegen gleichzeitig, wer als Nächstes sprechen sollte. Schreibe zuerst dein Kurzzeitgedächtnis fort, dann melde, wie dringend du jetzt das Wort brauchst.

Halte im Gedanken fest:
- was dir an den jüngsten Nachrichten auffällt (Zustimmung, Widerspruch, Lücken, offene Fragen an dich),
- was du als {{ $expert->name }} jetzt sagen würdest, wenn du an der Reihe wärst,
- was du dir für spätere Runden vornimmst.

Übernimm aus deinem bisherigen Kurzzeitgedächtnis, was noch gilt, und streiche, was erledigt ist. Höchstens sechs Sätze, kein Gesprächsbeitrag, keine Anrede.

Die Dringlichkeit ist eine Zahl von {{ $lowest }} bis {{ $highest }}: {{ $lowest }}, wenn du nichts beizutragen hast, {{ $highest }}, wenn dein Beitrag jetzt unbedingt nötig ist. Begründe sie nicht, gib nur die Zahl.

Pflichtformat, sonst nichts:
{{ $marker_thought }} <dein fortgeschriebener Gedanke>
{{ $marker_priority }} <Zahl von {{ $lowest }} bis {{ $highest }}>
```

- [ ] **Step 4: Run and watch it pass**

Run: `php artisan test --filter=ThinkAndPrioritizeTest`
Expected: PASS, all six.

- [ ] **Step 5: Failing test für den Selector**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HighestBidSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    /** @param  array<int, ?int>  $bids  expert index → priority */
    private function select(array $bids): \App\Discussion\Values\Selection
    {
        $payload = new TurnPayload($this->project, 1);

        foreach ($bids as $index => $priority) {
            $payload->addThought(new Thought($this->experts[$index]->id, 'Gedanke', priority: $priority));
        }

        return app(HighestBidSelector::class)->select($payload);
    }

    public function test_the_highest_bid_gets_the_floor(): void
    {
        $selection = $this->select([0 => 2, 1 => 5, 2 => 3]);

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertFalse($selection->tieBroken);
        $this->assertSame('HighestBidSelector', $selection->selector);
    }

    public function test_all_bids_are_recorded_in_the_signals(): void
    {
        $selection = $this->select([0 => 2, 1 => 5, 2 => 3]);

        $this->assertSame([
            $this->experts[0]->promptId => 2,
            $this->experts[1]->promptId => 5,
            $this->experts[2]->promptId => 3,
        ], $selection->signals['bids']);
        $this->assertSame([], $selection->signals['fallbacks']);
    }

    public function test_a_missing_bid_counts_as_the_lowest_and_is_recorded(): void
    {
        $selection = $this->select([0 => null, 1 => 2, 2 => 3]);

        $this->assertSame($this->experts[2]->id, $selection->speaker->id);
        $this->assertSame(1, $selection->signals['bids'][$this->experts[0]->promptId]);
        $this->assertSame([$this->experts[0]->promptId], $selection->signals['fallbacks']);
    }

    public function test_a_tie_goes_to_the_tie_breaker_and_is_flagged(): void
    {
        $selection = $this->select([0 => 5, 1 => 5, 2 => 1]);

        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[0]->id, $this->experts[1]->id]);
    }

    public function test_the_same_seed_and_turn_break_a_tie_the_same_way(): void
    {
        $first = $this->select([0 => 5, 1 => 5, 2 => 1])->speaker->id;

        $this->assertSame($first, $this->select([0 => 5, 1 => 5, 2 => 1])->speaker->id);
    }

    public function test_without_any_readable_bid_everyone_is_a_candidate(): void
    {
        $selection = $this->select([0 => null, 1 => null, 2 => null]);

        $this->assertTrue($selection->tieBroken);
        $this->assertCount(3, $selection->signals['fallbacks']);
    }

    public function test_an_expert_who_never_thought_still_bids_the_lowest(): void
    {
        // A stage that skipped someone must not make them unselectable for ever.
        $selection = $this->select([0 => 4]);

        $this->assertSame($this->experts[0]->id, $selection->speaker->id);
        $this->assertCount(2, $selection->signals['fallbacks']);
    }
}
```

- [ ] **Step 6: Run and watch it fail**

Run: `php artisan test --filter=HighestBidSelectorTest`
Expected: FAIL with `Class "App\Discussion\Selectors\HighestBidSelector" not found`.

- [ ] **Step 7: Selector und Pipeline anlegen**

```php
<?php

namespace App\Discussion\Selectors;

use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/**
 * The floor goes to the highest bid the agents made in ThinkAndPrioritize. No
 * model call of its own: the bids are already in the payload, so the selector is
 * pure evaluation.
 *
 * A missing or unreadable bid counts as the lowest and is listed under
 * signals.fallbacks — never as a reason to end the run, and never as a reason to
 * exclude that agent, which would make a single bad answer silence them.
 */
class HighestBidSelector implements SpeakerSelector
{
    private const LOWEST = 1;

    public function __construct(private readonly TieBreaker $tieBreaker) {}

    public function select(TurnPayload $payload): Selection
    {
        $experts = $payload->project->contributingExperts();

        $bids = [];
        $fallbacks = [];

        foreach ($experts as $expert) {
            $priority = $payload->thoughtOf($expert)?->priority;

            if ($priority === null) {
                $fallbacks[] = $expert->promptId;
                $priority = self::LOWEST;
            }

            $bids[$expert->promptId] = $priority;
        }

        $top = max($bids);
        $leaders = $experts->filter(fn (Expert $expert) => $bids[$expert->promptId] === $top)->values()->all();
        $tie = count($leaders) > 1;

        $speaker = $tie
            ? $this->tieBreaker->pick($leaders, $payload->project, $payload->turnIndex)
            : $leaders[0];

        return new Selection(
            speaker: $speaker,
            selector: class_basename(static::class),
            signals: ['bids' => $bids, 'fallbacks' => $fallbacks],
            tieBroken: $tie,
        );
    }
}
```

```php
<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAndPrioritize;

/** Everyone bids, the highest bid speaks. Priority bidding, as in Tak2026. */
class ScorePipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            ThinkAndPrioritize::class,
            SelectSpeaker::with(HighestBidSelector::class),
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
```

- [ ] **Step 8: Volle Suite und Commit**

Der `PipelineSmokeTest` nimmt die neue Pipeline von selbst auf (Ordner-Scan) und hat seine Antworten seit Task 3.

```bash
php artisan test
vendor/bin/pint app/Discussion/Stages/ThinkAndPrioritize.php app/Discussion/Selectors/HighestBidSelector.php app/Discussion/Pipelines/ScorePipeline.php tests/Unit/Discussion/ThinkAndPrioritizeTest.php tests/Unit/Discussion/HighestBidSelectorTest.php
git commit -m "Add the bidding cell: ThinkAndPrioritize, HighestBidSelector, ScorePipeline" -- app/Discussion/Stages/ThinkAndPrioritize.php resources/views/prompts/think/prioritize.blade.php app/Discussion/Selectors/HighestBidSelector.php app/Discussion/Pipelines/ScorePipeline.php tests/Unit/Discussion/ThinkAndPrioritizeTest.php tests/Unit/Discussion/HighestBidSelectorTest.php
```

---

### Task 5: Die Orchestrator-Zelle

**Files:**
- Create: `app/Discussion/Selectors/OrchestratorSelector.php`
- Create: `resources/views/prompts/select/orchestrator.blade.php`
- Create: `app/Discussion/Pipelines/OrchestratorPipeline.php`
- Test: `tests/Unit/Discussion/OrchestratorSelectorTest.php`

**Interfaces:**
- Consumes: `SelectorAgent`, `TieBreaker`, `Memory`, `PromptRenderer`, `ModelConfig`, `Thinking::section()`
- Produces: `OrchestratorSelector::MARKER_SPEAKER` (`'SPRECHER:'`), `MARKER_REASONING` (`'BEGRÜNDUNG:'`), `OrchestratorPipeline`

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Selectors\OrchestratorSelector;
use App\Discussion\TurnPayload;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class OrchestratorSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    private function select(): \App\Discussion\Values\Selection
    {
        return app(OrchestratorSelector::class)->select(new TurnPayload($this->project, 1));
    }

    public function test_the_named_expert_gets_the_floor(): void
    {
        $token = $this->experts[1]->promptId;
        FakeAgents::always(SelectorAgent::class, "SPRECHER: {$token}\nBEGRÜNDUNG: Weil die Frage an sie ging.");

        $selection = $this->select();

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame('Weil die Frage an sie ging.', $selection->reasoning);
        $this->assertFalse($selection->tieBroken);
        $this->assertSame('OrchestratorSelector', $selection->selector);
    }

    public function test_an_unknown_token_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(SelectorAgent::class, "SPRECHER: E999\nBEGRÜNDUNG: Irgendwer.");

        $selection = $this->select();

        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, array_map(fn (Expert $e) => $e->id, $this->experts));
    }

    public function test_a_missing_marker_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(SelectorAgent::class, 'Ich denke, Alice sollte sprechen.');

        $selection = $this->select();

        $this->assertTrue($selection->signals['fallback']);
    }

    public function test_a_user_token_is_not_a_speaker(): void
    {
        // Only contributing experts speak; a user token must not be accepted.
        FakeAgents::always(SelectorAgent::class, 'SPRECHER: U1');

        $selection = $this->select();

        $this->assertTrue($selection->signals['fallback']);
    }

    public function test_the_same_speaker_may_be_picked_twice_in_a_row(): void
    {
        // No balancing: repetition is the measurement, not an error.
        $token = $this->experts[0]->promptId;
        FakeAgents::always(SelectorAgent::class, "SPRECHER: {$token}");

        $this->assertSame($this->experts[0]->id, $this->select()->speaker->id);
        $this->assertSame($this->experts[0]->id, $this->select()->speaker->id);
    }

    public function test_the_prompt_carries_the_history_but_no_private_thought(): void
    {
        $this->project->addMessage('Ein Beitrag von Alice.', $this->experts[0]);
        \App\Models\Summary::create([
            'project_id' => $this->project->id,
            'expert_id' => $this->experts[0]->id,
            'content' => 'GEHEIMER GEDANKE',
        ]);

        $token = $this->experts[0]->promptId;
        FakeAgents::always(SelectorAgent::class, "SPRECHER: {$token}");

        $this->select();

        SelectorAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Ein Beitrag von Alice.')
            && ! str_contains($prompt->prompt, 'GEHEIMER GEDANKE'));
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --filter=OrchestratorSelectorTest`
Expected: FAIL with `Class "App\Discussion\Selectors\OrchestratorSelector" not found`.

- [ ] **Step 3: Selector, View und Pipeline anlegen**

```php
<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\Thinking;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Selection;
use App\Models\Expert;
use App\Models\Project;

/**
 * One model call picks the next speaker from the visible history, after AutoGen's
 * GroupChatManager. It sees History and Long-Term but never a private thought:
 * Memory::viewFor() without an expert leaves the Short-Term layer empty.
 *
 * It only picks. No role, no agenda, no instruction reaches the speaker — that
 * would steer how much the speaker writes, which is the study's second metric.
 */
class OrchestratorSelector implements SpeakerSelector
{
    public const MARKER_SPEAKER = 'SPRECHER:';

    public const MARKER_REASONING = 'BEGRÜNDUNG:';

    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly TieBreaker $tieBreaker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;

        $completion = (new SelectorAgent(
            ModelConfig::fromConfig($project->model),
            $payload->jobLogId,
        ))->ask($this->prompts->render('prompts.select.orchestrator', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
            'marker_speaker' => self::MARKER_SPEAKER,
            'marker_reasoning' => self::MARKER_REASONING,
        ] + $this->prompts->participants($project)));

        $reasoning = Thinking::section($completion->text, self::MARKER_REASONING);
        $token = trim(Thinking::section($completion->text, self::MARKER_SPEAKER, self::MARKER_REASONING));
        $speaker = $this->resolve($project, $token);

        if ($speaker !== null) {
            return new Selection(
                speaker: $speaker,
                selector: class_basename(static::class),
                signals: ['token' => $token, 'fallback' => false],
                reasoning: $reasoning,
            );
        }

        return new Selection(
            speaker: $this->tieBreaker->pick($project->contributingExperts()->values()->all(), $project, $payload->turnIndex),
            selector: class_basename(static::class),
            signals: ['token' => $token, 'fallback' => true],
            reasoning: $reasoning,
            tieBroken: true,
        );
    }

    /**
     * Only a contributing expert may speak. contributorByPromptId() resolves an
     * E token through contributorMap(), which holds exactly the contributing
     * experts, so the instanceof check is all that is left to do: a user token
     * (U3) or an unknown id lands on the fallback.
     */
    private function resolve(Project $project, string $token): ?Expert
    {
        $contributor = $project->contributorByPromptId($token);

        return $contributor instanceof Expert ? $contributor : null;
    }
}
```

`resources/views/prompts/select/orchestrator.blade.php`:

```blade
=== AUFGABE ===
Du moderierst eine Diskussion und entscheidest allein, wer als Nächstes spricht. Du sprichst nicht selbst und gibst keine Anweisungen.

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts, 'users' => $users])

@include('prompts.partials.memory', ['memory' => $memory])

=== ENTSCHEIDUNG ===
Wähle genau eine Person aus der Teilnehmerliste, die jetzt am meisten zum Gespräch beiträgt. Achte darauf, wer direkt angesprochen wurde, wo eine Frage offen ist und wessen Fachgebiet gerade gebraucht wird. Du darfst dieselbe Person auch mehrmals hintereinander wählen, wenn das sachlich richtig ist.

Pflichtformat, sonst nichts:
{{ $marker_speaker }} <Kennung aus der Teilnehmerliste, etwa E7>
{{ $marker_reasoning }} <ein Satz, warum>
```

```php
<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\OrchestratorSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAsSpeaker;

/** A model picks the next speaker from the history; only that speaker thinks. */
class OrchestratorPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            SelectSpeaker::with(OrchestratorSelector::class),
            ThinkAsSpeaker::class,
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
```

- [ ] **Step 4: Run and watch it pass**

Run: `php artisan test --filter=OrchestratorSelectorTest`
Expected: PASS, all six.

- [ ] **Step 5: Volle Suite und Commit**

```bash
php artisan test
vendor/bin/pint app/Discussion/Selectors/OrchestratorSelector.php app/Discussion/Pipelines/OrchestratorPipeline.php tests/Unit/Discussion/OrchestratorSelectorTest.php
git commit -m "Add the orchestrator cell: OrchestratorSelector and its pipeline" -- app/Discussion/Selectors/OrchestratorSelector.php resources/views/prompts/select/orchestrator.blade.php app/Discussion/Pipelines/OrchestratorPipeline.php tests/Unit/Discussion/OrchestratorSelectorTest.php
```

---

### Task 6: Die Entwurfsqualitäts-Zelle

**Files:**
- Create: `app/Discussion/Stages/ThinkAndPropose.php`
- Create: `resources/views/prompts/think/propose.blade.php`
- Create: `app/Discussion/Selectors/JudgeSelector.php`
- Create: `resources/views/prompts/select/judge.blade.php`
- Create: `app/Discussion/Pipelines/QualityPipeline.php`
- Test: `tests/Unit/Discussion/ThinkAndProposeTest.php`
- Test: `tests/Unit/Discussion/JudgeSelectorTest.php`

**Interfaces:**
- Consumes: `Thinking`, `JudgeAgent`, `TieBreaker`, `Memory`, `PromptRenderer`, `Thought`
- Produces: `ThinkAndPropose::MARKER_DRAFT` (`'ENTWURF:'`), `JudgeSelector::MARKER_SCORE` (`'BEWERTUNG:'`), `MARKER_REASONING` (`'BEGRÜNDUNG:'`), `QualityPipeline`

- [ ] **Step 1: Failing test für die Stage**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\ThinkAndPropose;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ThinkAndProposeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->project = Project::factory()->create();
        foreach (Expert::factory()->count(3)->create() as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    // Not run(): TestCase::run() is final and cannot be overridden.
    private function propose(): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1);

        return app(ThinkAndPropose::class)->handle($payload, fn (TurnPayload $p) => $p);
    }

    public function test_every_expert_delivers_a_thought_and_a_draft(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nENTWURF: Mein Entwurf lautet so.");

        $payload = $this->propose();

        $this->assertCount(3, $payload->thoughts());

        foreach ($this->project->contributingExperts() as $expert) {
            $thought = $payload->thoughtOf($expert);
            $this->assertSame('Mein Gedanke.', $thought->text);
            $this->assertSame('Mein Entwurf lautet so.', $thought->proposal);
        }
    }

    public function test_the_short_term_memory_of_every_agent_is_written(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nENTWURF: Entwurf.");

        $this->propose();

        $this->assertSame(3, Summary::where('project_id', $this->project->id)->count());
    }

    public function test_a_missing_draft_leaves_the_proposal_null(): void
    {
        FakeAgents::always(ThinkAgent::class, 'GEDANKE: Mein Gedanke.');

        $payload = $this->propose();

        $this->assertNull($payload->thoughtOf($this->project->contributingExperts()->first())->proposal);
    }

    public function test_a_missing_thought_marker_is_a_parse_failure(): void
    {
        FakeAgents::always(ThinkAgent::class, 'ENTWURF: Nur ein Entwurf.');

        $this->expectException(ParseFailure::class);

        $this->propose();
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `php artisan test --filter=ThinkAndProposeTest`
Expected: FAIL with `Class "App\Discussion\Stages\ThinkAndPropose" not found`.

- [ ] **Step 3: Stage und View anlegen**

```php
<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/** Everyone thinks and drafts a contribution. Belongs before SelectSpeaker(Judge). */
class ThinkAndPropose
{
    public const MARKER_DRAFT = 'ENTWURF:';

    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.propose', [
            'marker_thought' => Thinking::MARKER_THOUGHT,
            'marker_draft' => self::MARKER_DRAFT,
        ]);

        foreach ($experts as $expert) {
            $answer = $answers[$expert->id];
            $thought = Thinking::section($answer, Thinking::MARKER_THOUGHT, self::MARKER_DRAFT);

            if ($thought === '') {
                throw new ParseFailure(
                    "ThinkAndPropose: marker '".Thinking::MARKER_THOUGHT."' missing in the answer of expert {$expert->id}."
                );
            }

            $draft = Thinking::section($answer, self::MARKER_DRAFT);

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought, proposal: $draft === '' ? null : $draft));
        }

        return $next($payload);
    }
}
```

`resources/views/prompts/think/propose.blade.php` — wie `prioritize.blade.php` aufgebaut, mit dieser Aufgabe:

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
Alle Beteiligten entwerfen gleichzeitig einen Beitrag; anschließend entscheidet eine unabhängige Bewertung, welcher Entwurf gesprochen wird. Schreibe zuerst dein Kurzzeitgedächtnis fort, dann deinen Entwurf.

Halte im Gedanken fest:
- was dir an den jüngsten Nachrichten auffällt (Zustimmung, Widerspruch, Lücken, offene Fragen an dich),
- was du dir für spätere Runden vornimmst.

Übernimm aus deinem bisherigen Kurzzeitgedächtnis, was noch gilt, und streiche, was erledigt ist. Höchstens sechs Sätze.

Der Entwurf ist dein Diskussionsbeitrag, wie du ihn sagen würdest: als {{ $expert->name }}, in ganzen Sätzen, ohne Anrede an die Bewertung und ohne Begründung, warum er gut sei.

Pflichtformat, sonst nichts:
{{ $marker_thought }} <dein fortgeschriebener Gedanke>
{{ $marker_draft }} <dein Entwurf>
```

- [ ] **Step 4: Run and watch it pass**

Run: `php artisan test --filter=ThinkAndProposeTest`
Expected: PASS, all four.

- [ ] **Step 5: Failing test für den Judge**

```php
<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Selectors\JudgeSelector;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class JudgeSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    private function select(bool $withDrafts = true): \App\Discussion\Values\Selection
    {
        $payload = new TurnPayload($this->project, 1);

        if ($withDrafts) {
            foreach ($this->experts as $index => $expert) {
                $payload->addThought(new Thought($expert->id, 'Gedanke', proposal: "Entwurf {$index}"));
            }
        }

        return app(JudgeSelector::class)->select($payload);
    }

    public function test_the_highest_score_gets_the_floor(): void
    {
        FakeAgents::always(JudgeAgent::class, implode("\n", [
            "BEWERTUNG: {$this->experts[0]->promptId} 4",
            "BEWERTUNG: {$this->experts[1]->promptId} 9",
            "BEWERTUNG: {$this->experts[2]->promptId} 6",
            'BEGRÜNDUNG: Der zweite Entwurf ist am konkretesten.',
        ]));

        $selection = $this->select();

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame('Der zweite Entwurf ist am konkretesten.', $selection->reasoning);
        $this->assertSame('JudgeSelector', $selection->selector);
    }

    public function test_all_scores_are_recorded_in_the_signals(): void
    {
        FakeAgents::always(JudgeAgent::class, implode("\n", [
            "BEWERTUNG: {$this->experts[0]->promptId} 4",
            "BEWERTUNG: {$this->experts[1]->promptId} 9",
            "BEWERTUNG: {$this->experts[2]->promptId} 6",
        ]));

        $selection = $this->select();

        $this->assertSame([
            $this->experts[0]->promptId => 4,
            $this->experts[1]->promptId => 9,
            $this->experts[2]->promptId => 6,
        ], $selection->signals['scores']);
    }

    public function test_the_scores_decide_even_if_the_text_suggests_otherwise(): void
    {
        // The judge names no winner: a contradiction between prose and numbers
        // cannot arise, and the rule is the same as for the bids.
        FakeAgents::always(JudgeAgent::class, implode("\n", [
            "BEWERTUNG: {$this->experts[0]->promptId} 2",
            "BEWERTUNG: {$this->experts[1]->promptId} 8",
            "BEGRÜNDUNG: Eigentlich finde ich den ersten Entwurf besser.",
        ]));

        $this->assertSame($this->experts[1]->id, $this->select()->speaker->id);
    }

    public function test_an_unscored_expert_is_recorded_as_a_fallback(): void
    {
        FakeAgents::always(JudgeAgent::class, "BEWERTUNG: {$this->experts[0]->promptId} 5");

        $selection = $this->select();

        $this->assertSame($this->experts[0]->id, $selection->speaker->id);
        $this->assertContains($this->experts[1]->promptId, $selection->signals['fallbacks']);
        $this->assertContains($this->experts[2]->promptId, $selection->signals['fallbacks']);
    }

    public function test_no_readable_score_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(JudgeAgent::class, 'Ich kann mich nicht entscheiden.');

        $selection = $this->select();

        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
    }

    public function test_a_tie_between_two_scores_goes_to_the_tie_breaker(): void
    {
        FakeAgents::always(JudgeAgent::class, implode("\n", [
            "BEWERTUNG: {$this->experts[0]->promptId} 7",
            "BEWERTUNG: {$this->experts[1]->promptId} 7",
        ]));

        $selection = $this->select();

        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[0]->id, $this->experts[1]->id]);
    }

    public function test_the_prompt_shows_the_drafts_with_their_author(): void
    {
        FakeAgents::always(JudgeAgent::class, "BEWERTUNG: {$this->experts[0]->promptId} 5");

        $this->select();

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Entwurf 0')
            && str_contains($prompt->prompt, 'Entwurf 1')
            && str_contains($prompt->prompt, $this->experts[0]->promptId));
    }
}
```

- [ ] **Step 6: Run and watch it fail**

Run: `php artisan test --filter=JudgeSelectorTest`
Expected: FAIL with `Class "App\Discussion\Selectors\JudgeSelector" not found`.

- [ ] **Step 7: Selector, View und Pipeline anlegen**

```php
<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\Thinking;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/**
 * One model call scores the drafts the agents wrote in ThinkAndPropose; the
 * highest score gets the floor.
 *
 * The judge scores but never names a winner: the selector derives it from the
 * numbers, so the answer cannot contradict itself, and the rule is the same one
 * HighestBidSelector applies to the bids.
 */
class JudgeSelector implements SpeakerSelector
{
    public const MARKER_SCORE = 'BEWERTUNG:';

    public const MARKER_REASONING = 'BEGRÜNDUNG:';

    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly TieBreaker $tieBreaker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;
        $experts = $project->contributingExperts();

        $drafts = [];
        foreach ($experts as $expert) {
            $proposal = $payload->thoughtOf($expert)?->proposal;

            if ($proposal !== null) {
                $drafts[$expert->promptId] = ['name' => $expert->name, 'draft' => $proposal];
            }
        }

        $completion = (new JudgeAgent(
            ModelConfig::fromConfig($project->model),
            $payload->jobLogId,
        ))->ask($this->prompts->render('prompts.select.judge', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
            'drafts' => $drafts,
            'marker_score' => self::MARKER_SCORE,
            'marker_reasoning' => self::MARKER_REASONING,
        ] + $this->prompts->participants($project)));

        $reasoning = Thinking::section($completion->text, self::MARKER_REASONING);
        $scores = $this->scores($completion->text, $experts->all());

        $fallbacks = [];
        foreach ($experts as $expert) {
            if (! isset($scores[$expert->promptId])) {
                $fallbacks[] = $expert->promptId;
            }
        }

        if ($scores === []) {
            return new Selection(
                speaker: $this->tieBreaker->pick($experts->values()->all(), $project, $payload->turnIndex),
                selector: class_basename(static::class),
                signals: ['scores' => [], 'fallbacks' => $fallbacks, 'fallback' => true],
                reasoning: $reasoning,
                tieBroken: true,
            );
        }

        $top = max($scores);
        $leaders = $experts->filter(fn (Expert $expert) => ($scores[$expert->promptId] ?? null) === $top)->values()->all();
        $tie = count($leaders) > 1;

        return new Selection(
            speaker: $tie ? $this->tieBreaker->pick($leaders, $project, $payload->turnIndex) : $leaders[0],
            selector: class_basename(static::class),
            signals: ['scores' => $scores, 'fallbacks' => $fallbacks, 'fallback' => false],
            reasoning: $reasoning,
            tieBroken: $tie,
        );
    }

    /**
     * Every "BEWERTUNG: E7 8" line whose token is a contributing expert.
     *
     * @param  list<Expert>  $experts
     * @return array<string, int>
     */
    private function scores(string $text, array $experts): array
    {
        $known = [];
        foreach ($experts as $expert) {
            $known[$expert->promptId] = true;
        }

        preg_match_all('/'.preg_quote(self::MARKER_SCORE, '/').'\s*(\S+)\s+(\d+)/u', $text, $matches, PREG_SET_ORDER);

        $scores = [];
        foreach ($matches as [, $token, $score]) {
            if (isset($known[$token])) {
                $scores[$token] = (int) $score;
            }
        }

        return $scores;
    }
}
```

`resources/views/prompts/select/judge.blade.php`:

```blade
=== AUFGABE ===
Du bewertest Entwürfe für den nächsten Diskussionsbeitrag. Du schreibst selbst keinen Beitrag und wählst keinen Gewinner aus; du gibst nur Punkte.

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.memory', ['memory' => $memory])

=== ENTWÜRFE ===
@foreach ($drafts as $token => $draft)
[{{ $token }}] {{ $draft['name'] }}:
{{ $draft['draft'] }}

@endforeach

=== BEWERTUNG ===
Gib jedem Entwurf eine Zahl von 1 bis 10. Hoch bewertest du, was die Diskussion jetzt voranbringt: ein neues Argument, eine belastbare Zahl, eine offene Frage beantwortet. Niedrig bewertest du Wiederholung, Füllsätze und Beiträge, die am Thema vorbeigehen. Bewerte die Sache, nicht die Person.

Pflichtformat, eine Zeile je Entwurf, sonst nichts:
{{ $marker_score }} <Kennung> <Zahl von 1 bis 10>
{{ $marker_reasoning }} <ein Satz zur Begründung>
```

```php
<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\JudgeSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAndPropose;

/**
 * Everyone drafts, a judge awards the floor. The winner then writes with the
 * Speak prompt and their draft as context: the length of a contribution is a
 * measured value and must come from the same source as in every other cell.
 */
class QualityPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            ThinkAndPropose::class,
            SelectSpeaker::with(JudgeSelector::class),
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
```

- [ ] **Step 8: Run and watch it pass**

Run: `php artisan test --filter=JudgeSelectorTest`
Expected: PASS, all seven.

- [ ] **Step 9: Volle Suite und Commit**

```bash
php artisan test
vendor/bin/pint app/Discussion/Stages/ThinkAndPropose.php app/Discussion/Selectors/JudgeSelector.php app/Discussion/Pipelines/QualityPipeline.php tests/Unit/Discussion/ThinkAndProposeTest.php tests/Unit/Discussion/JudgeSelectorTest.php
git commit -m "Add the proposal-quality cell: ThinkAndPropose, JudgeSelector, QualityPipeline" -- app/Discussion/Stages/ThinkAndPropose.php resources/views/prompts/think/propose.blade.php app/Discussion/Selectors/JudgeSelector.php resources/views/prompts/select/judge.blade.php app/Discussion/Pipelines/QualityPipeline.php tests/Unit/Discussion/ThinkAndProposeTest.php tests/Unit/Discussion/JudgeSelectorTest.php
```

---

### Task 7: Dokumentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/pipeline.puml`

**Interfaces:**
- Consumes: den Endzustand aus Tasks 1 bis 6
- Produces: nichts Ausführbares

- [ ] **Step 1: `CLAUDE.md` nachziehen**

`CLAUDE.md` ist gitignored und nie versioniert — auf der Platte bearbeiten, nicht committen, und im Report sagen, dass die Datei geändert wurde.

Was hineingehört:

- Der Abschnitt zur Turn-Pipeline listet jetzt vier Pipelines statt „RoundRobinPipeline is the only pipeline implemented so far". Für jede eine Zeile mit ihrer Stage-Kette.
- Die zwei neuen Think-Stages in der Stage-Aufzählung, mit dem Hinweis, dass sie alle Agenten fragen, während `ThinkAsSpeaker` nur den Sprecher fragt — und dass daraus ein Unterschied im Kurzzeitgedächtnis zwischen den Zellen folgt, der in die Limitationen der Arbeit gehört.
- `SpeakerSelector` hat vier Implementierungen; `HighestBidSelector` ruft kein Modell, die anderen zwei je genau eines.
- Der `TieBreaker` und dass `projects.seed` damit gelesen wird — der bisherige Eintrag unter „Still open" („random tie-breaks are currently not reproducible") ist erledigt und verschwindet.
- `Purpose::Judge` neben den vier bestehenden Werten.
- Der Riegel an `StudyAgent::prompt()` und warum: `ask()` ist der einzige Weg, sonst nimmt ein Aufruf das Standardmodell statt des Projektmodells.
- Was in `job_logs.selection.signals` je Mechanismus steht (`bids`, `scores`, `fallbacks`, `token`, `fallback`) und dass jeder Fallback dort protokolliert ist, damit die Auswertung ihn berichten oder den Turn ausschließen kann.
- Unter „Still open" bleiben: die Persona-/Statusschicht, das Auswertungsskript, `RunExperiment`.

- [ ] **Step 2: `docs/pipeline.puml` nachziehen**

Die Datei zuerst lesen und ihre Notation beibehalten. Aufzunehmen sind die drei neuen Pipelines mit ihren Ketten, die zwei Think-Stages, die drei Selektoren und der `TieBreaker`; die Kanten zum Modell führen bei den Selektoren über `SelectorAgent` und `JudgeAgent`.

- [ ] **Step 3: Abschließend prüfen**

```bash
php artisan test
vendor/bin/pint --test app/Discussion
```

Expected: Suite grün, Pint ohne Befund für `app/Discussion`.

- [ ] **Step 4: Commit**

```bash
git commit -m "Document the four speaker-selection pipelines" -- docs/pipeline.puml
```

---

## Abschluss-Prüfung gegen die Akzeptanzkriterien der Spec

- [ ] `php artisan tinker`: `app(App\Discussion\Pipelines\PipelineRegistry::class)->options()` gibt vier Einträge zurück
- [ ] `php artisan test --filter=PipelineSmokeTest` fährt vier Pipelines je zwei Turns
- [ ] Ein Score-Turn schreibt die Gebote aller Agenten nach `job_logs.selection.signals.bids`
- [ ] Ein Orchestrator-Turn schreibt die Begründung nach `job_logs.selection.reasoning`
- [ ] Ein Quality-Turn schreibt die Punkte aller Agenten, und der Gewinner ist der mit den meisten
- [ ] Zwei Läufe mit demselben Seed und derselben Modellantwort wählen bei Gleichstand denselben Sprecher
- [ ] Jeder Fallback aus Abschnitt 4.8 der Spec ist im Log erkennbar, und keiner beendet den Lauf
- [ ] Ein Selector, der `prompt()` mit einem fremden Provider aufruft, scheitert im Test
- [ ] `vendor/bin/pint --test app/Discussion` ohne Befund
