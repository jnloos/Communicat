# Umstieg auf das Laravel AI SDK: Design

Stand: 24. September 2026. Branch: `Studie-JanNiclas-Loosen`.
Vorgänger-Spec: `docs/superpowers/specs/2026-09-20-generische-turn-pipeline-design.md`.
Geprüfte SDK-Version: `laravel/ai` v1.0.0, veröffentlicht am 23. September 2026.

## 1. Ziel

Die eigene LLM-Schicht `App\Llm` weicht dem offiziellen Laravel AI SDK. Grundsatz: keine eigene
Abstraktion dort, wo das Framework eine mitbringt. Die Messung der Studie bleibt dabei
unverändert — gleiche Tabellen, gleiche Spalten, gleiche Zählregeln, gleiches `run_config`-Format.

Was der Umstieg bringt: die Anbieter-Adapter, das Call-Logging, die Token-Buchhaltung und die
Test-Fakes kommen aus dem Framework statt aus eigenem Code. Rund 900 Zeilen eigener
Infrastruktur entfallen, darunter drei handgeschriebene Anbieter-Adapter.

Was er nicht bringt: parallele Aufrufe. Die bleiben eigener Code (Abschnitt 4.5).

## 2. Getroffene Entscheidungen

| Thema | Entscheidung |
|---|---|
| Umfang | `App\Llm` entfällt vollständig; kein eigenes Client-Interface bleibt als Port stehen |
| Aufrufform | eine Agent-Klasse je Zweck unter `App\Discussion\Agents`, mit `Promptable` |
| Prompts | die Blade-Views bleiben unangetastet; `instructions()` rendert `prompts.system`, der gerenderte View geht als Text in `prompt()` |
| Modellwahl | `config/llm.php` bleibt als Studien-Registry, `config/ai.php` hält nur die Zugangsdaten — **überholt, siehe 4.2**: der Nutzer hat entschieden, `config/llm.php` ganz aufzulösen; die Registry lebt jetzt in `config/ai.php` |
| `ModelConfig` | bleibt unverändert — sie bestimmt das Format von `run_config` und `prompt_logs.config` |
| Messung | ein Event-Listener auf `StepCompleted`/`StepFailed` ersetzt `LoggingLlmClient` |
| Antwort-Träger | neues `Completion` ersetzt `LlmResponse`; schmal, weil es Prozessgrenzen überlebt |
| Parallelität | eigener `ParallelPrompts` über Laravels `Concurrency`, weil das SDK keine Batch-API hat |
| Tests | `Agent::fake()` ersetzt `FakeLlmClient`/`FakeLlmFactory` |
| Failover | verboten, siehe Abschnitt 5 |
| Conversation-Storage des SDK | nicht benutzt, siehe Abschnitt 5 |
| Datenbank | keine Migration, keine neue Spalte, keine Umbenennung |
| PHP | Constraint von `^8.2` auf `^8.3`, weil das SDK es verlangt |

## 3. Was das SDK mitbringt und was nicht

Am Quellcode von v1.0.0 geprüft, nicht der Doku entnommen:

- **Aufruf:** `prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null,
  ?string $model = null, ?int $timeout = null)`. Synchron.
- **Antwort:** `TextResponse` mit `text`, `reasoning` (leerer String, wenn der Anbieter keines
  liefert), `usage` und `meta`.
- **Token:** `TextUsage` mit `inputTokens`, `outputTokens`, `cacheReadInputTokens`,
  `cacheWriteInputTokens`, `reasoningTokens`. Reasoning ist eine Teilmenge der Output-Token.
- **Herkunft:** `Meta` mit `provider` und `model` — das Modell, das der Anbieter zurückmeldet, also
  unser `checkpoint`.
- **Events, dispatcht in `Gateway\RunContext`:** `PromptingAgent(invocationId, prompt)`,
  `StepCompleted(invocationId, stepNumber, agent, provider, model, isFinalStep, response, time)`,
  `StepFailed(… , exception, time)`, dazu `AgentPrompted` und `AgentFailed` je Invocation.
  `time` ist Wandzeit in Millisekunden. `Prompts\Prompt` trägt den Prompt-Text in `$prompt`.
  Die Agent-Instanz reist im Event mit, eigene Properties sind im Listener also lesbar.
- **Optionen am Agenten:** `maxTokens()`, `temperature()`, `topP()`, `maxSteps()` dürfen Methoden
  sein; `Gateway\TextGenerationOptions::forAgent()` liest die Methode und fällt erst dann auf das
  gleichnamige Attribut zurück. Damit ist die Modellkonfiguration pro Projekt dynamisch.
- **Anbieter-Optionen:** `HasProviderOptions::providerOptions(Lab|string): array`, also
  `Lab::OpenAI => ['reasoning' => ['effort' => 'low']]` und
  `Lab::Anthropic => ['thinking' => ['budget_tokens' => n]]`.
- **Fakes:** `Agent::fake(Closure|array $responses)` liefert ein `FakeTextGateway`, dazu
  `assertPrompted()` und ein Schalter gegen unerwartete Prompts.
- **Migrationen:** `publishesMigrations`, also opt-in. Ohne `RemembersConversations` entsteht keine
  Tabelle.
- **Nicht vorhanden:** parallele Ausführung. Im gesamten `src/` kein Treffer für `pool`, `parallel`,
  `concurrent`, `batch`, `async` oder `promise`. Nur synchron, Queue-Job oder Stream.

## 4. Aufbau

### 4.1 Agenten

Neuer Namespace `App\Discussion\Agents`. Eine Klasse je Zweck: `ThinkAgent`, `SpeakAgent`,
`SummarizeAgent`. Die noch nicht gebauten Selektoren bringen später `SelectorAgent` und
`JudgeAgent` mit, ohne dass hier etwas geändert wird.

Gemeinsam ist allen eine abstrakte Basis `StudyAgent`:

- implementiert `Laravel\Ai\Contracts\Agent` und `HasProviderOptions`, nutzt `Promptable`;
- Konstruktor: `ModelConfig $model`, `?int $jobLogId = null`, `?int $expertId = null`;
- `instructions()` gibt `PromptRenderer::system()` zurück;
- `maxTokens()` gibt `$model->maxOutputTokens`, `temperature()` gibt `$model->temperature`;
- `providerOptions(Lab|string $provider)` bildet `$model->reasoningEffort` auf den Schlüssel des
  jeweiligen Anbieters ab und gibt für Gemini ein leeres Array zurück;
- `purpose(): string` ist abstrakt und je Unterklasse eine Konstante.

Damit verschwindet der Sonderfall aus `GeminiClient::make()`: statt einer Exception, wenn
`reasoning_effort` gesetzt ist, liefert `providerOptions()` für `Lab::Gemini` einfach keinen
Effort-Schlüssel. Der Kommentar in `config/llm.php` zum fehlenden Effort-Mapping bleibt gültig und
wandert mit.

Die vier Konstanten aus `LlmRequest::PURPOSE_*` werden ein Enum `App\Discussion\Purpose` mit den
Fällen `Think`, `Select`, `Speak`, `Summarize`. Die Werte bleiben zeichengleich (`think`, `select`,
`speak`, `summarize`), weil sie in `prompt_logs.purpose` stehen und bestehende Läufe vergleichbar
bleiben müssen.

### 4.2 Modellwahl

> **Korrektur (nach Umsetzung):** Der Nutzer hat entschieden, `config/llm.php` nicht zu behalten,
> sondern ganz aufzulösen — nicht nur `providers` und `keys` zu streichen, wie der Rest dieses
> Abschnitts noch beschreibt. Die Studien-Registry (`models`, `default_model`, vormals `default`)
> lebt jetzt als eigener Block in `config/ai.php`, neben den SDK-eigenen Schlüsseln `default`
> (Standard-*Provider*) und `providers` (Zugangsdaten). `config/llm.php` existiert nicht mehr. Der
> Rest dieses Abschnitts beschreibt den ursprünglichen Plan und ist in dem Punkt überholt.

`config/llm.php` bleibt, verliert aber den `providers`- und den `keys`-Block: Adapter-Klassen und
Zugangsdaten sind Sache des SDK und stehen künftig in `config/ai.php`. Was bleibt, ist die
Studien-Registry — Key, Label, Modell-ID, `max_output_tokens`, `temperature`, `reasoning_effort` —
plus `default`. Der `provider`-Eintrag jedes Modells bleibt als String und wird beim Aufruf über
`Lab::from()` zum Enum.

`ModelConfig` behält `fromConfig()` und ein zeichengleiches `toArray()`: sie ist das Format von
`projects.run_config.model` und `prompt_logs.config`, und eine Änderung daran würde bestehende Läufe
und den JSON-Export unvergleichbar machen. Geändert wird nur die Hülle — die Klasse zieht nach
`App\Discussion\Values` und bekommt ein `lab(): Lab`.

`LlmFactory` entfällt. Wer ein Modell braucht, ruft `ModelConfig::fromConfig($project->model)`.
Die Validierung unbekannter Modell-Keys bleibt dort, wo sie heute ist.

### 4.3 Completion

`App\Discussion\Values\Completion` ersetzt `LlmResponse`:

```php
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

Bewusst schmal: Diese Objekte queren bei parallelen Aufrufen eine Prozessgrenze. Eine
`AgentResponse` kann das nicht — sie trägt Collections, `Meta` und über `HasRawResponse` die rohe
HTTP-Antwort. `reasoningTokens` ist nullable, weil nicht jeder Anbieter sie meldet; `TextUsage`
unterscheidet dort zwischen null und 0, und diese Unterscheidung bleibt erhalten.

Modell, Checkpoint, Latenz und Finish-Reason fehlen absichtlich: sie werden nur geloggt, und das
Logging liest sie direkt aus dem Event.

### 4.4 Messung

`prompt_logs` und `job_logs` bleiben schemagleich. `TurnRunner` bleibt bis auf eine Zeile
unverändert; er misst aus dem `TurnPayload`, nicht aus dem Client.

Neu ist `App\Discussion\Logging\RecordPromptLog`, ein Listener mit drei Handlern:

- `PromptingAgent` — merkt `invocationId => prompt->prompt` in einer Map im Speicher;
- `StepCompleted` — schreibt die Zeile und räumt den Eintrag aus der Map;
- `StepFailed` — schreibt die Zeile mit `status: failed` und der Exception, räumt ebenso auf;
- `AgentFailed` — Auffangnetz für Fehler, bei denen es nie zu einem Step kam; schreibt nur, wenn
  für die `invocationId` noch ein Eintrag in der Map steht.

Die Map ist prozesslokal und kurzlebig. Unsere Agenten führen keine Tools, ein Prompt ist also
genau ein Step und genau eine Zeile in `prompt_logs` — wie heute.

Spaltenweise:

| Spalte | Quelle |
|---|---|
| `job_log_id` | `$event->agent->jobLogId` |
| `purpose` | `$event->agent->purpose()` |
| `expert_id` | `$event->agent->expertId` |
| `label` | `purpose` bzw. `purpose:expertId`, wie heute |
| `provider`, `model` | `ModelConfig` des Agenten |
| `checkpoint` | `$event->response->meta->model` |
| `config` | `ModelConfig::toArray()` |
| `prompt` | Prompt-Text aus `PromptingAgent` |
| `response`, `reasoning` | `$event->response->text` / `->reasoning` |
| `tokens_in`, `tokens_out`, `tokens_reasoning` | `$event->response->usage` |
| `latency_ms` | `(int) $event->time` |
| `status`, `error` | `ok`, bzw. `failed` plus Fehlertext aus `StepFailed` |

Das Verhalten von `LoggingLlmClient` bleibt damit erhalten: **jeder** Aufruf wird protokolliert,
Fehlschläge werden nie gefiltert, weil die Studie Fehlerraten je Modell berichtet.

### 4.5 Parallele Prompts

`App\Discussion\Support\ParallelPrompts` ersetzt den Trait `CompletesConcurrently`:
`run(array $tasks): array` nimmt `array<key, Closure(): Completion>` und gibt
`array<key, Completion>` zurück, Schlüssel erhalten. Jede Closure muss serialisierbar bleiben, darf
also kein Agent- oder Model-Objekt einfangen, sondern nur Skalare — den Agenten baut sie selbst.

- Bei einem einzigen Auftrag wird direkt aufgerufen, ohne Prozess.
- Sonst `Concurrency::run()`. Jede Closure baut ihren Agenten im Kindprozess neu und reduziert das
  Ergebnis dort **sofort** auf ein `Completion`. Über die Prozessgrenze gehen nur skalare Werte und
  dieses Value Object — nie eine `AgentResponse`.
- Die Events feuern im Kindprozess, die `prompt_logs`-Zeilen entstehen also dort. Das ist heute
  genauso und funktioniert, weil der Kindprozess eine eigene Datenbankverbindung öffnet.

In `phpunit.xml` kommt `<env name="CONCURRENCY_DRIVER" value="sync"/>`. Ohne das startet
`Concurrency::run()` Kindprozesse, die die In-Memory-SQLite der Testsuite nicht sehen und an
`Agent::fake()` vorbeilaufen. Heute fällt das nicht auf, weil nur ein Request pro Turn anfällt und
Tests den echten Pfad nie nehmen; mit den Bieter-Selektoren würde es auffallen.

### 4.6 Änderungen in der Pipeline

Die Pipeline-Struktur ändert sich nicht. Unberührt bleiben `TurnPipeline`, `PipelineRegistry`,
`RoundRobinPipeline`, `TurnPayload` samt `MissingPayloadSlot`-Semantik, `Selection`, `Memory` mit
allen drei Schichten, `PromptRenderer`, alle Blade-Prompts, die `PipelineStageChanged`-Events, die
Message-Persistenz mit Adjacency Pairs, der `run_config`-Snapshot und die Zählregel
`words IS NOT NULL`.

| Stelle | Änderung |
|---|---|
| `Stages\SelectSpeaker` | keine; `RoundRobinSelector` ruft kein Modell |
| `Stages\ThinkAsSpeaker` | keine; der Aufruf liegt in `Support\Thinking` |
| `Support\Thinking::ask()` | Agent-Bau statt `LlmRequest`, `ParallelPrompts` statt `completeMany`; Rückgabetyp `array<int, string>` bleibt |
| `Stages\Speak` | Konstruktor ohne `LlmFactory`, der Aufruf, und `parse(Completion …)` statt `parse(LlmResponse …)` |
| `Stages\PersistMessage` | keine |
| `Stages\Summarize` | Konstruktor ohne `LlmFactory` und der Aufruf; Fensterlogik unberührt |
| `Values\Contribution` | Feld `$response` (`LlmResponse`) wird `$completion` (`Completion`) |
| `Values\Thought` | keine; Gedanken werden in Wörtern und Zeichen gemessen, nicht in Token |
| `TurnRunner::measure()` | `$contribution->response->tokensReasoning` wird `$contribution->completion->reasoningTokens` |

Die Parse-Logik in `Speak` bleibt zeichengleich: der Split am `---STEUERUNG---`-Marker, `ADRESSAT`,
`PAARTYP`, und die Regel, dass der sichtbare Text nie geparst wird.

`LlmException` zieht als `App\Discussion\ParseFailure` um und behält nur den Parse-Fall — API- und
Transportfehler wirft künftig das SDK (`ProviderConnectionException`, `RateLimitedException`,
`ProviderOverloadedException` und weitere unter `Laravel\Ai\Exceptions`). Für den `TurnRunner`
ändert das nichts: er fängt `Throwable`, schreibt `status: failed` samt Meldung und stoppt den Loop.

`docs/pipeline.puml` ändert sich nur an den Kanten, die zum Modell zeigen.

## 5. Verbote

Zwei Fähigkeiten des SDK dürfen in diesem Projekt nicht benutzt werden. Beide sind für gewöhnliche
Anwendungen sinnvoll und für diese Studie schädlich.

**Kein Failover.** `prompt()` nimmt für `provider:` auch ein Array und wechselt dann bei einem
Fehler still den Anbieter. Ein Run muss in einer homogenen Modellfamilie bleiben; ein stiller
Wechsel macht die Zelle unbrauchbar, ohne im Log aufzufallen. Also immer genau ein Provider. Dazu
ein Listener auf `ProviderFailedOver` und `AgentFailedOver`, der über `Log::error` laut wird, falls
es doch passiert.

**Kein `RemembersConversations`.** Das Gedächtnis der Studie sind die drei Memory-Schichten und
`messages`. Die `conversations`-Tabelle des SDK wäre ein zweites, konkurrierendes Gedächtnis —
genau der Fehler, den das Projekt mit zwei parallelen Summary-Systemen schon einmal hatte. Die
Migrationen des SDK werden deshalb nicht publiziert.

Ebenfalls nicht benutzt, ohne dass es weh tut: Tools, Approvals, Streaming, strukturierte Ausgaben,
Klassifikation, Sub-Agenten, Middleware. Sollte ein späterer Selector strukturierte Ausgaben
brauchen (Priorität 1–5, Sprecherwahl), ist `HasStructuredOutput` der vorgesehene Weg — das ist
eine eigene Entscheidung zu ihrer Zeit, nicht Teil dieses Umstiegs.

## 6. Datenmodell

Keine Änderung. Keine Migration, keine neue Spalte, keine Umbenennung, kein Backfill. `prompt_logs`
und `job_logs` werden weiter mit denselben Werten befüllt, `projects.run_config` behält sein Format.
Bestehende Läufe bleiben auswertbar und mit neuen vergleichbar.

## 7. Tests

`tests/Fakes/FakeLlmClient.php` und `tests/Fakes/FakeLlmFactory.php` entfallen, ebenso das gesamte
Verzeichnis `tests/Unit/Llm` — die dort getesteten Adapter gibt es nicht mehr.

An ihre Stelle tritt ein Helfer `tests/Fakes/FakeAgents.php`, der pro Agent-Klasse Antworten
staffelt (`ThinkAgent::fake([...])` usw.) und damit dieselbe Rolle spielt wie heute das Stapeln nach
`purpose`. Umzustellen sind die Stage-Tests unter `tests/Unit/Discussion`, `PipelineSmokeTest`,
`TurnRunnerTest` und `MessageGeneratorTest`. `fakeAnswers()` im Smoke-Test bleibt als Konzept, nur
die Adressierung wechselt von `purpose` auf die Agent-Klasse.

Neu zu testen:

- `RecordPromptLog` — je eine Zeile bei Erfolg und bei Fehlschlag, mit korrektem Mapping aller
  Spalten, und kein Leck in der `invocationId`-Map;
- `StudyAgent` — `maxTokens()`, `temperature()` und `providerOptions()` je Anbieter, insbesondere
  das leere Array für Gemini;
- `ParallelPrompts` — Schlüssel bleiben erhalten, ein einzelner Auftrag nimmt den direkten Pfad;
- `ModelConfig::lab()` und der unveränderte `toArray()`-Snapshot.

Der Rest der Suite fasst den LLM-Pfad nicht an. Zielzustand: die heutigen 152 Tests laufen weiter
grün, abzüglich der gelöschten LLM-Adapter-Tests und zuzüglich der neuen.

## 8. Aufräumarbeiten im selben Zug

- `app/Llm/` wird gelöscht: `LlmClient`, `LlmFactory`, `LoggingLlmClient`, `LlmRequest`,
  `LlmResponse`, `LlmException`, `Providers\OpenAiClient`, `Providers\AnthropicClient`,
  `Providers\GeminiClient`, `Providers\CompletesConcurrently`. `ModelConfig` zieht nach
  `app/Discussion/Values`.
- `composer.json`: `anthropic-ai/sdk` und `openai-php/client` raus, `laravel/ai` rein, `php` auf
  `^8.3`. Der Gemini-Zugriff lief über Guzzle; `guzzlehttp/guzzle` bleibt, weil Laravel es ohnehin
  zieht.
- `config/ai.php` wird publiziert und auf die drei genutzten Anbieter reduziert.
- `config/llm.php` verliert `providers` und `keys`. **Korrektur (nach Umsetzung):** der Nutzer hat
  stattdessen entschieden, die Datei ganz aufzulösen; `models` und `default_model` (vormals
  `default`) ziehen komplett nach `config/ai.php`, siehe 4.2.
- `CLAUDE.md`: der Abschnitt „LLM layer" wird neu geschrieben, die Verbote aus Abschnitt 5 kommen
  dazu. Die sieben ohnehin offenen Verbesserungen an dieser Datei werden im selben Zug eingetragen.
- `docs/pipeline.puml` nachziehen.

## 9. Reihenfolge

1. SDK installieren, `config/ai.php` publizieren und reduzieren, PHP-Constraint anheben.
   `config/llm.php` bleibt in diesem Schritt vollständig — `providers` und `keys` werden noch von
   `LlmFactory` und den Adaptern gelesen, die erst in Schritt 6 verschwinden. Suite muss weiter grün
   sein, weil noch nichts umgestellt ist.
2. `Purpose`, `Completion`, `ModelConfig::lab()`, `StudyAgent` und die drei Agenten — test-first.
3. `RecordPromptLog` samt Tests, registriert im `AppServiceProvider`.
4. `Support\Thinking` umstellen, `Speak` umstellen, `Summarize` umstellen, `Contribution` und die
   eine Zeile in `TurnRunner` anpassen. Stage-Tests wandern mit.
5. `ParallelPrompts`, `CONCURRENCY_DRIVER=sync` in `phpunit.xml`.
6. `app/Llm` und die beiden alten Anbieter-SDKs löschen, Fakes und `tests/Unit/Llm` entfernen, und
   erst jetzt `providers` und `keys` aus `config/llm.php` streichen. **Korrektur (nach Umsetzung):**
   tatsächlich wurde `config/llm.php` in diesem Schritt vollständig gelöscht, siehe 4.2 und
   Abschnitt 8.
7. Failover-Wächter, Doku, `vendor/bin/pint`, volle Suite.

Nach jedem Schritt läuft die Suite. Schritt 4 ist der einzige, der die Pipeline berührt, und er ist
in sich abgeschlossen: davor und danach ist das Verhalten identisch.

## 10. Risiken

| Risiko | Umgang |
|---|---|
| SDK ist einen Tag alt, v1.0.0 mit Breaking Changes gegenüber der Beta | wir starten direkt auf 1.0, es gibt nichts zu migrieren; Version in `composer.json` pinnen und bewusst anheben |
| `reasoningTokens` wird je Anbieter unterschiedlich gemeldet | Spalte ist nullable, `TextUsage` unterscheidet null von 0; die Studie zählt Wörter und Zeichen, Token sind nur Nebenbefund |
| stiller Anbieter-Wechsel durch Failover | genau ein Provider je Aufruf, plus Wächter-Listener |
| Kindprozesse und In-Memory-SQLite | `CONCURRENCY_DRIVER=sync` in der Testumgebung |
| `AgentResponse` ist nicht prozessübergreifend serialisierbar | Reduktion auf `Completion` schon im Kindprozess |
| Prompt-Caching des SDK könnte Token-Zahlen verschieben | Caching wird in diesem Umstieg nicht aktiviert; falls später, dann als eigene Entscheidung mit Blick auf `uncachedInputTokens()` |

## 11. Bewusst nicht gebaut

Kein Wrapper, kein eigenes Interface, keine Fassade vor dem SDK — der Sinn des Umstiegs ist, dass
die Abstraktion aus dem Framework kommt. Kein Strangler-Pfad mit zwei parallelen Wegen: es gibt
genau einen Aufrufpfad und 152 grüne Tests, die den Umbau absichern. Keine Nutzung von Queue oder
Streaming für die Turn-Aufrufe; der `TurnRunner` bleibt synchron.

## 12. Akzeptanzkriterien

- `app/Llm` existiert nicht mehr, `anthropic-ai/sdk` und `openai-php/client` sind aus
  `composer.json` verschwunden.
- Ein Turn über `RoundRobinPipeline` schreibt dieselben Zeilen in `job_logs` und `prompt_logs` wie
  vorher: gleiche Spalten belegt, `purpose` und `label` zeichengleich, `status: failed` mit Meldung
  bei einem Anbieterfehler.
- `projects.run_config` hat nach einem ersten Turn dasselbe Format wie vor dem Umstieg.
- Die Suite ist grün, `vendor/bin/pint` meldet nichts.
- Ein zweiter Anbieter ist eine Zeile in `config/llm.php`, keine neue Klasse. **Korrektur (nach
  Umsetzung):** diese Zeile steht jetzt in `config/ai.php` unter `models`, siehe 4.2.
