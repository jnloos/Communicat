# Generische Turn-Pipeline: Design

Stand: 20. September 2026. Branch: `Studie-JanNiclas-Loosen`.
Diagramme: `docs/archify/ziel-architektur.html`, `docs/archify/ziel-pipelines.html`.
Fachlicher Rahmen: `docs/plans/vorhabensuebersicht.pdf`.

## 1. Ziel

Die App wird zur Versuchsplattform für die Frage, ob LLM-Agenten den Statuseffekt auf den
Redeanteil reproduzieren und ob dessen Stärke am Sprecherwahl-Mechanismus oder am Modell hängt.
Dafür braucht sie:

- mehrere Pipelines, die sich nur in Reihenfolge, Think-Variante und Selector unterscheiden,
- ein Modell je Projekt, wählbar aus OpenAI, Anthropic und Gemini,
- eine Messung je Turn, die keine Pipeline abschalten kann.

Leitplanken: schnell verständlich, nah am Bestand, eine neue Pipeline ist eine neue Klasse mit
rund zwölf Zeilen und ohne Edit an anderer Stelle.

## 2. Getroffene Entscheidungen

| Thema | Entscheidung |
|---|---|
| Pipeline-Struktur | Stage-Komposition über Laravels `Pipeline`; jede Pipeline ist eine Klasse, die eine geordnete Stage-Liste zurückgibt |
| Reihenfolge | frei, die Pipeline bestimmt, wer wann denkt und wo der Summarizer steht; geprüft wird zur Laufzeit |
| Think | je Variante eine eigene Stage mit eigenem Blade-View, keine Enums |
| Erweiterungspunkte | genau zwei: Stage und `SpeakerSelector` |
| Kennung der Pipeline | kurzer Klassenname, etwa `ScorePipeline`; Auto-Discovery per Ordner |
| Memory | drei Schichten nach Nonomura2025: History, Short-Term, Long-Term |
| Short-Term | ein fortgeschriebener Gedanke je Agent und Projekt in `summaries`, jedes Think überschreibt ihn |
| Long-Term | geteilte Zusammenfassung aus dem Summarizer, ohne Embeddings |
| Modell | ein Modell je Projekt für alle Aufrufe; keine Fast/Slow-Trennung |
| Messung | der `TurnRunner` protokolliert immer, auch Fehlschläge |
| Datenbank | keine neuen Tabellen, keine Umbenennungen, nur Spalten |
| Bestand | die Moderator-Pipeline wird auf diesem Branch gelöscht; `main` behält sie |
| Nutzer | dürfen weiter Nachrichten schreiben, sie erscheinen in der History; eine Sonderbehandlung (Übergabe, Adressierung) gibt es nicht mehr |

Abweichungen von Nonomura2025, im Methodenteil zu benennen: ein fortgeschriebener Gedanke statt
der letzten k Gedanken; Long-Term als geteilte Zusammenfassung statt je Agent mit
Embedding-Retrieval.

## 3. Aufbau

Neuer Namespace `App\Discussion`. Der alte Namespace `App\Services\PromptingPipeline` entfällt.

```
app/Discussion/
  TurnRunner.php            führt einen Turn aus und protokolliert ihn
  TurnPayload.php           Träger durch die Stages
  TurnResult.php            stop + reason für den Aufrufer
  GenerationLoop.php        Cache-Flags: isGenerating, markViewing, hasViewers, Lock
  Values/                   Thought, Selection, Contribution
  Pipelines/
    TurnPipeline.php        Interface: stages(): array
    PipelineRegistry.php    findet alle Klassen in diesem Ordner
    RoundRobinPipeline.php, ScorePipeline.php, OrchestratorPipeline.php, QualityPipeline.php
  Stages/
    ThinkAsSpeaker.php, ThinkAndPrioritize.php, ThinkAndPropose.php
    SelectSpeaker.php, Speak.php, PersistMessage.php, Summarize.php
  Selectors/
    SpeakerSelector.php     Interface: select(TurnPayload): Selection
    RoundRobinSelector.php, HighestBidSelector.php, OrchestratorSelector.php, JudgeSelector.php
    TieBreaker.php          zufällige Wahl mit Seed, nie ausgleichend
  Memory/
    Memory.php, MemoryView.php
  Persona/
    StatusCondition.php     Enum: None, Titled, Neutral
    PersonaRenderer.php
  Support/
    Thinking.php            gemeinsamer Teil der drei Think-Stages
    PromptRenderer.php      rendert Blade-Views, dekodiert HTML-Entities

app/Llm/
  LlmClient.php             Interface: complete(), completeMany()
  LlmRequest.php, LlmResponse.php, ModelConfig.php
  LlmFactory.php            Modell-Schlüssel des Projekts → Client
  LoggingLlmClient.php      Decorator, schreibt prompt_logs
  Providers/OpenAiClient.php, AnthropicClient.php, GeminiClient.php
```

### 3.1 Pipeline und Registry

`TurnPipeline` hat eine Methode: `stages(): array`. Die Einträge sind Klassennamen oder
Laravel-Pipe-Strings der Form `Klasse:parameter`. Laravels `Pipeline` baut jede Stage über den
Container, Dependency Injection funktioniert also, und reicht die Parameter als Zusatzargumente
an `handle()`. `SelectSpeaker::with(HighestBidSelector::class)` erzeugt diesen String.

Die `PipelineRegistry` scannt `app/Discussion/Pipelines/*.php`, leitet per PSR-4 den Klassennamen
ab und behält, was `TurnPipeline` implementiert. `all()` liefert kurzer Name → Klasse,
`resolve(string $name)` die Instanz. Anzeigename ist `__("pipelines.$name")`, falls vorhanden,
sonst der Klassenname.

### 3.2 Die vier Pipelines

```
RoundRobinPipeline    SelectSpeaker(RoundRobin) → ThinkAsSpeaker → Speak → PersistMessage → Summarize
ScorePipeline         ThinkAndPrioritize → SelectSpeaker(HighestBid) → Speak → PersistMessage → Summarize
OrchestratorPipeline  SelectSpeaker(Orchestrator) → ThinkAsSpeaker → Speak → PersistMessage → Summarize
QualityPipeline       ThinkAndPropose → SelectSpeaker(Judge) → Speak → PersistMessage → Summarize
```

Bei `QualityPipeline` folgt auf die Auswahl ein eigener `Speak`; der Gewinner bekommt sein
Proposal in den Speak-Prompt. Der Gedächtnisunterschied zwischen den Pipelines (alle denken
jeden Turn gegenüber nur der Sprecher) gehört zur jeweiligen Architektur und wird in den
Limitationen diskutiert.

### 3.3 TurnPayload

Veränderlicher Träger wie der heutige `TurnContext`, mit typisierten Feldern:

- `project`, `turnIndex`, `jobLogId`
- `thoughts`: Expert-ID → `Thought` (Text, optional `priority`, optional `proposal`)
- `selection`: `Selection` (Sprecher, Signale je Agent, Begründung, `tieBroken`)
- `contribution`: `Contribution` (sichtbarer Text, Adressat, Paartyp, `LlmResponse`)
- `message`, `stop`, `reason`

Getter brechen mit einer sprechenden Meldung ab, wenn ein Feld fehlt, etwa
„Speak braucht eine Selection, SelectSpeaker muss davor stehen".

### 3.4 TurnRunner

Ersetzt `DiscussionPipeline`. Ablauf: `job_logs`-Zeile anlegen, Pipeline über die Registry
auflösen, Payload durch die Stages schicken, danach die Messung in `job_logs` schreiben. Wirft
eine Stage, schreibt der Runner Status `failed` mit Fehlertext und gibt `stop` zurück. Das
UI-Ereignis `PipelineStageChanged` (`routing`, `thinking`, `speaking` samt beteiligter Experten)
senden die Stages wie bisher selbst, weil nur sie die Beteiligten kennen.

Aufrufer sind `MessageGenerator` (interaktiv; Viewer-Heartbeat und Lesepause bleiben dort) und
später `RunExperiment` (kopflos). Der Runner kennt keinen der beiden.

Stopp-Bedingungen: `turn_budget` erreicht, keine Kandidaten, Fehlschlag.

### 3.5 Selectors

`SpeakerSelector::select(TurnPayload): Selection`. Kein Selector gleicht Beteiligung aus.
Gleichstände löst `TieBreaker` zufällig auf, gespeist aus `projects.seed` und `turnIndex`.
`OrchestratorSelector` und `JudgeSelector` sehen History und Long-Term, nie die privaten
Gedanken der Agenten. `JudgeSelector` sieht die Proposals mit Absender.

### 3.6 Memory

`Memory::viewFor(Project, ?Expert): MemoryView` liefert drei Schichten:

- **History**: die letzten n Nachrichten, für alle Agenten gleich.
- **Short-Term**: der eine Gedanke des Agenten aus `summaries`. Inhalt: Gedanken zu den letzten
  Nachrichten und was der Persona auf der Zunge brennt. Think bekommt den bisherigen Gedanken
  in den Prompt und schreibt ihn fort.
- **Long-Term**: `projects.long_term_memory`.

Regel: Long-Term deckt alles bis `summarized_until_message_id` ab, History den Rest. `Summarize`
läuft, sobald mehr als n + b Nachrichten unzusammengefasst sind, und verdichtet die ältesten b.
Der Prompt bekommt die bisherige Zusammenfassung und die b Nachrichten und liefert die neue.
Damit ist der heutige Fehler behoben, dass jeder Lauf die alte Zusammenfassung überschreibt.
n und b stehen in `config/discussion.php` und im `run_config`-Schnappschuss.

Ein Blade-Partial `prompts/partials/memory` rendert die `MemoryView`, damit der Block in allen
Prompts und Pipelines gleich aufgebaut ist. `Project::asPromptArray()`,
`Message::toPromptArray()` und `Expert::asPromptArray()` entfallen; die `promptId`-Accessoren
bleiben.

### 3.7 Persona und Status

`StatusCondition`: `None` (normales Projekt), `Titled` (der Agent auf `titled_seat` trägt den
Titel), `Neutral` (derselbe Agent bekommt einen neutralen Zusatztext gleicher Länge). Beide
Texte stehen in `lang/de/persona.php`. `PersonaRenderer` rendert Name, Job, Beschreibung und den
Zusatz über `prompts/partials/persona`. Ein Unit-Test prüft, dass Titel- und Zusatztext in
Wörtern und Zeichen gleich lang sind. Die Rotation des betitelten Sitzes ist Aufgabe des
späteren `RunExperiment`-Commands.

### 3.8 Prompts

Alle Prompts bleiben Blade-Views unter `resources/views/prompts/`:

```
system.blade.php
think/speaker.blade.php, think/prioritize.blade.php, think/propose.blade.php
speak.blade.php
select/orchestrator.blade.php, select/judge.blade.php
summarize.blade.php
partials/memory.blade.php, partials/persona.blade.php
```

Ausgabe-Marker definiert die jeweilige Stage einmal als Konstante und reicht sie als Variable in
den View; der Parser nutzt dieselbe Konstante. Der System-Prompt wird von der Stage gerendert
und im `LlmRequest` übergeben, die Provider-Adapter kennen keine Views. `Speak` behält den
`---STEUERUNG---`-Trailer für Adressat und Paartyp.

### 3.9 LLM-Schicht

`LlmClient::complete(LlmRequest): LlmResponse` und `completeMany(array): array` für parallele
Aufrufe. Parallelität läuft wie im Bestand über Laravels `Concurrency::run`.

Adapter nutzen das offizielle SDK, wo es eines gibt: `openai-php/client` (schon installiert) für
OpenAI, `anthropic-ai/sdk` für Anthropic. Für Gemini gibt es kein offizielles PHP-SDK, dort
Laravels `Http`-Client. Jeder Adapter besteht aus zwei reinen Abbildungsfunktionen
(`buildParams`, `mapResponse`), die ohne Netzwerk testbar sind, und einem dünnen Aufruf. `LlmRequest`: System-Prompt, Prompt, `ModelConfig`, Zweck
(`think|select|speak|summarize`), optional Expert-ID. `LlmResponse`: sichtbarer Text, Reasoning
soweit der Anbieter es liefert, angefragtes Modell, zurückgemeldeter Checkpoint, Token-Zahlen
(Eingabe, Ausgabe, Reasoning), Latenz, `finishReason`.

`config/llm.php` listet die wählbaren Modelle: Schlüssel → Provider, Modell-ID, Temperatur,
Token-Limit, Reasoning-Stufe. API-Keys bleiben in der `.env`. `LlmFactory::for(Project)` baut
aus `projects.model` den Adapter und umhüllt ihn mit `LoggingLlmClient`. Das statische
`bindJobLog` entfällt, die Job-Log-ID steht im Request.

Reasoning: Kein Anbieter liefert das rohe Reasoning zuverlässig aus. Anthropic gibt auf
aktuellen Modellen höchstens eine Zusammenfassung zurück (`thinking.display: summarized`),
OpenAI ebenfalls nur Zusammenfassungen plus `reasoningTokens`. Gespeichert wird, was kommt:
Zusammenfassungstext in `prompt_logs.reasoning`, Token-Zahl in `tokens_reasoning`. Die
Reasoning-Länge wird je Modell in Tokens berichtet und nicht über Modelle verglichen.

Temperatur ist optional: aktuelle Anthropic-Modelle und OpenAI-Reasoning-Modelle lehnen den
Parameter ab. `ModelConfig.temperature` ist nullable und wird nur gesendet, wenn gesetzt; im
Bericht steht dann „nicht einstellbar".

Serverseitige Refusal-Fallbacks von Anthropic bleiben bewusst aus. Ein stiller Wechsel auf ein
anderes Modell würde den Modellfaktor verfälschen. Eine Verweigerung ist ein gezählter
Fehlschlag.

## 4. Datenmodell

Die bestehenden `create_*`-Migrationen werden angepasst, danach `migrate:fresh`.

| Tabelle | Änderung |
|---|---|
| `projects` | neu: `pipeline`, `model`, `status_condition`, `titled_seat` (nullable), `seed`, `turn_budget` (nullable), `run_config` (JSON), `long_term_memory` (nullable), `summarized_until_message_id` (nullable, bewusst ohne Fremdschlüssel, sonst entstünde ein Kreis `projects` → `messages` → `projects`). Entfällt: `settings` |
| `project_contributors` | neu: `seat` |
| `job_logs` | neu: `turn_index`, `expert_id` (Sprecher), `seat`, `words`, `chars`, `thought_words`, `thought_chars`, `reasoning_tokens`, `selection` (JSON), `error` |
| `prompt_logs` | neu: `provider`, `checkpoint`, `config` (JSON), `purpose`, `expert_id`, `reasoning`, `tokens_in`, `tokens_out`, `tokens_reasoning`, `status`, `error` |
| `summaries` | Schema unverändert; `user_id` verschwindet aus dem Model |
| `messages`, `experts`, `users` | unverändert |

Die Verknüpfung Turn → Nachricht besteht schon über `messages.job_log_id`.

`run_config` hält je Projekt den Schnappschuss: Stage-Liste der Pipeline, Modell-Parameter, n und b.
Gezählt werden nur öffentliche Beiträge, in Wörtern und Zeichen, nie in Tokens.

## 5. Aufräumarbeiten im selben Zug

1. `EditProject::save()` überschreibt heute das ganze `settings`-Array und löscht damit das
   Langzeitgedächtnis. Entfällt mit der Spalte.
2. `summary_frequency` wird geschrieben, aber nie gelesen. Das Feld verschwindet aus den
   Formularen; an seine Stelle treten die Dropdowns für Pipeline und Modell.
3. Schleifensteuerung aus `Jobs\Dependencies\ProjectJob` in `GenerationLoop`.
4. Willkommensnachricht aus `Project::created` in `CreateProject`; der Hook setzt `user_id`
   nicht mehr über `auth()`.
5. Reasoning-Budget nicht mehr über das Log-Label, sondern als Modell-Parameter.
6. `ProjectTransfer` (Export, Import) an das neue Schema anpassen; das Umschreiben von
   `last_summarized_id` im JSON-Blob entfällt zugunsten der FK-Spalte.
7. `CLAUDE.md` und `docs/pipeline.puml` aktualisieren.

Gelöscht werden: `ModeratorService`, `AgentService`, `Summarizer`, `PromptBuilder`, `Directive`,
`TurnContext`, `Candidates/*`, alle Stages unter `PromptingPipeline/Stages`, `OpenAIClient`, die
Views `prompts/moderator/*` und `prompts/agent/*` sowie die zugehörigen Tests.
`MemoryFormatter` und das Gedanken-Flyout werden auf das neue Gedankenformat umgestellt.

## 6. Fehlerbehandlung

Ein fehlgeschlagener LLM-Aufruf wird in `prompt_logs` mit Status und Fehler gespeichert. Ein
Parse-Fehler (etwa keine Priorität 1 bis 5 erkennbar) zählt als Fehlschlag des Aufrufs. Der Turn
schlägt dann fehl, der Runner schreibt `job_logs.status = failed`, die Schleife stoppt. Ein
fehlgeschlagener Lauf wird gezählt und berichtet, nicht stillschweigend gefiltert. Ob es
Wiederholungsversuche gibt, entscheidet der Pilot.

## 7. Tests

- `FakeLlmClient` mit festgelegten Antworten; keine echten API-Aufrufe.
- Smoke-Test: jede von der Registry gefundene Pipeline läuft zwei Turns durch. Neue Pipelines
  sind automatisch abgedeckt.
- Je Stage und je Selector ein Unit-Test; `TieBreaker` ist mit Seed reproduzierbar und
  bevorzugt nie seltene Sprecher.
- `Memory`: History und Long-Term überlappen nicht; der Summarizer schreibt fort.
- Status-Texte gleich lang in Wörtern und Zeichen.
- Je Provider-Adapter ein Test mit `Http::fake` auf Request-Form und Antwort-Mapping.
- Registry findet eine Test-Pipeline im Ordner.

## 8. Teilprojekte und Reihenfolge

Die Teilprojekte 1 bis 3 stehen gemeinsam in
`docs/superpowers/plans/2026-09-20-generische-turn-pipeline.md`, weil erst ihr Zusammenspiel
eine lauffähige App ergibt und alle gefundenen Bestandsfehler dort liegen. Die übrigen bekommen
eigene Pläne. Jeder Task endet mit grüner Suite.

1. **LLM-Schicht.** `LlmClient`, drei Adapter, Logging-Decorator, `config/llm.php`,
   `projects.model`, neue `prompt_logs`-Spalten. Die alte Pipeline bleibt bis zur Umstellung
   in Teilprojekt 3 unangetastet auf `OpenAIClient`; eine Brücke wäre Wegwerfarbeit.
2. **Durchstich.** `TurnPayload`, `TurnRunner`, Registry, `RoundRobinPipeline` mit
   `SelectSpeaker`, `Speak`, `PersistMessage`, Messung in `job_logs`, Smoke-Test,
   Pipeline-Dropdown. Entscheidet, ob sich die Struktur richtig anfühlt.
3. **Memory und Think.** `Memory`, `ThinkAsSpeaker`, `Summarize` mit Fortschreibung, Spalten
   statt `settings`, Aufräumarbeiten 1 bis 4, Löschen des Bestands.
4. **Persona-Schicht.** `StatusCondition`, `PersonaRenderer`, `seat`, `titled_seat`.
5. **Auswertungsskript**, geprüft an künstlichen Extremfällen.
6. **Übrige Pipelines.** `ThinkAndPrioritize`, `ThinkAndPropose`, drei Selektoren.
7. **`RunExperiment`** mit Matrix, Rotation und Turn-Budget; danach der Pilot.

## 9. Bewusst nicht gebaut

- Deklaration von Stage-Voraussetzungen mit Prüfung beim Start.
- Eigene `runs`-Tabelle; ein Projekt ist ein Lauf.
- Abweichendes Selektor-Modell für die Gegenprobe; bei Bedarf eine nullable Spalte.
- Embedding-Retrieval und Long-Term je Agent.
- Versionierung von Gedanken und Zusammenfassungen; `prompt_logs` hält jeden Prompt im Volltext.
