# Values konsequent führen und Adjacency auf `addressee` reduzieren: Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alle reinen Datentypen liegen unter `App\Discussion\Values`, und von der Adjacency-Metadaten einer Nachricht bleibt genau eine Angabe übrig: welchen beitragenden Experten sie anspricht.

**Architecture:** Drei Aufgaben, jede mit grüner Suite am Ende. Aufgabe 1 verschiebt drei Klassen, ohne Verhalten zu ändern. Aufgabe 2 entfernt den selbstberichteten Paartyp aus Wertobjekt, Stage, Prompt, Export und Anzeige — die Datenbankspalte bleibt dabei stehen und läuft leer. Aufgabe 3 ersetzt die polymorphe Partner-Relation durch ein `belongsTo(Expert)` und räumt die Spalten auf.

**Tech Stack:** Laravel 12, PHP 8.3+, PHPUnit 11, SQLite (in-memory im Test), Pint, Livewire/Flux, Blade.

**Spec:** `docs/superpowers/specs/2026-09-26-values-und-adjacency-design.md`
**Roadmap:** `docs/superpowers/specs/2026-09-26-studie-umbau-roadmap.md` (Stücke 1 und 2 von sieben)

## Global Constraints

- **Kein Produktivsystem.** Migrationen werden an Ort und Stelle geändert, nicht als Folgemigration hinterhergeschoben. Keine Datenübernahme, kein `down()`, das Altdaten rettet. Der Datenbestand entsteht aus `php artisan dev:build-suite`.
- **Kein Alias, kein `class_alias`, keine Übergangsklasse** am alten Ort einer verschobenen Klasse. Ein halb verschobener Typ wäre schlimmer als der jetzige Zustand.
- **Einordnungsregel für `Values`:** dorthin gehört, was unveränderlich ist, keinen Service kennt und nichts tut außer Werte tragen.
- **Benennung:** `addressee`. Spalte `messages.addressee_expert_id`, Relation `$message->addressee()`, Wertobjekt-Feld `Contribution::$addresseeToken`, Exportschlüssel `addressee_expert_id`. Nicht `talks_to`, nicht `opens_pair_with`, nicht `partner`.
- **Keine Abwärtskompatibilität** für Exportdateien im alten Format. Alte Dateien importieren fehlerfrei, nur ohne Adressaten.
- **Nach jeder Aufgabe:** `php artisan test` grün und `vendor/bin/pint` sauber, bevor committet wird.
- **Tests fassen nie einen echten Anbieter an.** `phpunit.xml` leert die API-Keys; Modellantworten kommen aus `Tests\Fakes\FakeAgents` (`always()`/`inOrder()` mit Closure, nie mit Array).
- **Nicht Teil dieses Plans:** die Marker-Konstante `Speak::MARKER_CONTROL` und das Regex-Parsing des `ADRESSAT`-Feldes. Beides fällt erst mit Stück 3 der Roadmap (Structured Output). Dieser Plan nimmt dem `STEUERUNG`-Block nur die `PAARTYP`-Zeile.

---

## Dateistruktur

**Aufgabe 1 — verschieben (kein Verhalten):**

| Datei | Verantwortung |
|---|---|
| Neu: `app/Discussion/Values/MemoryView.php` | was ein Agent zu sehen bekommt (History, Short-Term, Long-Term) |
| Neu: `app/Discussion/Values/TurnResult.php` | Ergebnis eines Turns: weiterlaufen oder anhalten, mit Grund |
| Neu: `app/Discussion/Values/Purpose.php` | Enum der fünf Aufrufzwecke |
| Löschen: `app/Discussion/Memory/MemoryView.php`, `app/Discussion/TurnResult.php`, `app/Discussion/Purpose.php` | — |
| Ändern: `app/Discussion/Memory/Memory.php`, `app/Discussion/TurnRunner.php`, die fünf Agents, `StudyAgent.php` | nur Importzeilen |
| Ändern: `tests/Unit/Discussion/PromptRendererTest.php`, `tests/Unit/Discussion/StudyAgentTest.php` | nur Importzeilen |
| Ändern: `CLAUDE.md` | Einordnungsregel aufnehmen, FQN von `Purpose` korrigieren |

**Aufgabe 2 — Paartyp entfernen:**

| Datei | Verantwortung danach |
|---|---|
| `app/Discussion/Values/Contribution.php` | Text, Adressaten-Token, Completion — drei Felder |
| `app/Discussion/Stages/Speak.php` | trennt sichtbaren Text vom Trailer, liest nur noch `ADRESSAT` |
| `app/Discussion/Stages/PersistMessage.php` | speichert Nachricht und Adressat, setzt keinen Vorgabewert mehr |
| `resources/views/prompts/speak.blade.php` | `STEUERUNG`-Block ohne `PAARTYP` |
| `app/Services/ProjectTransfer/ProjectExport.php` | Export ohne Paartyp |
| `app/Services/ProjectTransfer/ProjectImporter.php` | Import ohne Paartyp |
| `resources/views/livewire/debug/job-debug-panel.blade.php` | Nachrichtenzeile ohne Paartyp |
| `tests/Unit/Discussion/SpeakTest.php`, `tests/Unit/Discussion/PersistMessageTest.php` | angepasst |

**Aufgabe 3 — Morph zu `addressee`:**

| Datei | Verantwortung danach |
|---|---|
| `database/migrations/2025_05_30_152442_create_messages_table.php` | `addressee_expert_id` statt Paartyp-Spalte und Morph |
| `app/Models/Message.php` | `addressee(): BelongsTo`, keine `PAIR_*`-Konstanten |
| `app/Discussion/Stages/PersistMessage.php` | verknüpft über `addressee()` |
| `app/Events/MessageGenerated.php` | Nutzlast mit `addressed_expert_id`, ohne `addressed_user_id` |
| `app/Services/ProjectTransfer/ProjectExport.php` / `ProjectImporter.php` | ein Feld `addressee_expert_id` statt zweier Partnerfelder |
| `resources/views/components/projects/chat-message.blade.php` | löst den Adressaten ohne `instanceof`-Weiche auf |
| `resources/views/components/projects/addressed-chip.blade.php` | ohne `isExpert`-Prop |
| `resources/views/livewire/debug/job-debug-panel.blade.php` | Adressat über die neue Relation |
| `tests/Unit/MessageGeneratedPayloadTest.php`, `tests/Unit/Discussion/PersistMessageTest.php` | angepasst |
| `CLAUDE.md` | Datenmodell- und Pipeline-Beschreibung korrigiert |

---

### Task 1: Values-Konsolidierung

Rein mechanisch. Es gibt keinen neuen Test — der Beweis ist, dass die bestehende Suite unverändert grün bleibt. Deshalb steht hier die Verifikation vor der Änderung: erst den grünen Ausgangszustand festhalten, dann verschieben, dann vergleichen.

**Files:**
- Create: `app/Discussion/Values/MemoryView.php`, `app/Discussion/Values/TurnResult.php`, `app/Discussion/Values/Purpose.php`
- Delete: `app/Discussion/Memory/MemoryView.php`, `app/Discussion/TurnResult.php`, `app/Discussion/Purpose.php`
- Modify: `app/Discussion/Memory/Memory.php:1-10`, `app/Discussion/TurnRunner.php:1-16`, `app/Discussion/Agents/StudyAgent.php:5`, `app/Discussion/Agents/ThinkAgent.php:5`, `app/Discussion/Agents/SpeakAgent.php`, `app/Discussion/Agents/SelectorAgent.php`, `app/Discussion/Agents/JudgeAgent.php`, `app/Discussion/Agents/SummarizeAgent.php`, `CLAUDE.md:140,160`
- Test: `tests/Unit/Discussion/PromptRendererTest.php`, `tests/Unit/Discussion/StudyAgentTest.php` (nur Importzeilen)

**Interfaces:**
- Consumes: nichts
- Produces: `App\Discussion\Values\MemoryView` (Konstruktor `array $history, string $shortTerm, string $longTerm`), `App\Discussion\Values\TurnResult` (`::proceed()`, `::stop(string $reason)`, Felder `bool $stop`, `?string $reason`), `App\Discussion\Values\Purpose` (String-Enum mit `Think`, `Select`, `Speak`, `Summarize`, `Judge`). Signaturen und Feldnamen bleiben unverändert — nur der Namensraum wechselt.

- [ ] **Step 1: Den grünen Ausgangszustand festhalten**

```bash
php artisan test 2>&1 | tail -5
```

Die Zeile mit der Testanzahl notieren (`Tests: N passed`). Genau diese Zahl muss am Ende wieder herauskommen. Weicht sie ab, ist etwas anderes passiert als ein Verschieben.

- [ ] **Step 2: Die drei Dateien am neuen Ort anlegen**

`app/Discussion/Values/MemoryView.php`:

```php
<?php

namespace App\Discussion\Values;

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

`app/Discussion/Values/TurnResult.php`:

```php
<?php

namespace App\Discussion\Values;

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

`app/Discussion/Values/Purpose.php`:

```php
<?php

namespace App\Discussion\Values;

/** The kinds of model call. Values are stored in prompt_logs.purpose. */
enum Purpose: string
{
    case Think = 'think';
    case Select = 'select';
    case Speak = 'speak';
    case Summarize = 'summarize';
    case Judge = 'judge';
}
```

- [ ] **Step 3: Die drei alten Dateien löschen**

```bash
git rm app/Discussion/Memory/MemoryView.php app/Discussion/TurnResult.php app/Discussion/Purpose.php
```

`app/Discussion/Memory/` enthält danach nur noch `Memory.php`. Das ist beabsichtigt: der Ordner trägt den Service, nicht dessen Rückgabewert.

- [ ] **Step 4: Nachweisen, dass jetzt etwas kaputt ist**

```bash
php artisan test --filter=PromptRendererTest 2>&1 | tail -20
```

Erwartet: Fehler wegen nicht gefundener Klasse `App\Discussion\Memory\MemoryView`. Das ist der Beweis, dass die Importe wirklich auf den alten Ort zeigten und Step 5 nötig ist.

- [ ] **Step 5: Die Importzeilen umstellen**

`app/Discussion/Memory/Memory.php` — nach den bestehenden `use`-Zeilen einfügen (alphabetisch vor `App\Models\Expert`):

```php
use App\Discussion\Values\MemoryView;
```

`app/Discussion/TurnRunner.php` — neben die bestehende Zeile `use App\Discussion\Values\ModelConfig;`:

```php
use App\Discussion\Values\TurnResult;
```

In `app/Discussion/Agents/StudyAgent.php` und in allen fünf Agent-Klassen (`ThinkAgent`, `SpeakAgent`, `SelectorAgent`, `JudgeAgent`, `SummarizeAgent`) die Zeile

```php
use App\Discussion\Purpose;
```

ersetzen durch

```php
use App\Discussion\Values\Purpose;
```

Dasselbe in `tests/Unit/Discussion/StudyAgentTest.php`. In `tests/Unit/Discussion/PromptRendererTest.php` die Zeile mit `App\Discussion\Memory\MemoryView` ersetzen durch `App\Discussion\Values\MemoryView`.

Danach prüfen, dass nichts übrig ist:

```bash
grep -rn 'App\\Discussion\\Purpose\|App\\Discussion\\TurnResult\|App\\Discussion\\Memory\\MemoryView' app tests
```

Erwartet: keine Ausgabe.

- [ ] **Step 6: Suite und Stil prüfen**

```bash
php artisan test 2>&1 | tail -5
vendor/bin/pint
```

Erwartet: dieselbe Testanzahl wie in Step 1, alles grün, Pint ohne Beanstandung.

- [ ] **Step 7: Die Einordnungsregel in CLAUDE.md schreiben**

In `CLAUDE.md` in `### Data model notes` als ersten Aufzählungspunkt einfügen:

```markdown
- **Where a type lives:** `App\Discussion\Values` holds what is immutable, knows no service and does nothing but carry values — `Completion`, `Contribution`, `ModelConfig`, `MemoryView`, `Purpose`, `Selection`, `Thought`, `TurnResult`. `TurnPayload` is not one of them (it is mutable and collects state during the turn), nor is `GenerationLoop` (cache-backed), nor are the exceptions. A new data type goes there, not next to the service that returns it.
```

Ausserdem in derselben Datei den Namensraum von `Purpose` korrigieren: `App\Discussion\Purpose` → `App\Discussion\Values\Purpose` (eine Stelle, im Abschnitt zur LLM-Schicht).

- [ ] **Step 8: Commit**

```bash
git add app/Discussion CLAUDE.md tests/Unit/Discussion/PromptRendererTest.php tests/Unit/Discussion/StudyAgentTest.php
git commit -m "Put every value type in Values, not just most of them

MemoryView, TurnResult and Purpose followed the same rule as the five
types already under Values -- immutable, no service, nothing but values
to carry -- but lived elsewhere, so the directory said less than it
looked like it said. They move; nothing else changes. The rule itself
now stands in CLAUDE.md so the next call is not a matter of taste.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Der Paartyp verschwindet

`adjacency_pair_type` hält eines von vier Etiketten, selbstberichtet vom sprechenden Modell. Keine Zielgröße der Studie liest es. Hier fällt alles, was es erhebt, weitergibt und anzeigt. Die Datenbankspalte bleibt in dieser Aufgabe noch stehen und läuft leer — sie fällt in Aufgabe 3 mit der Migration.

Im selben Zug heißt `Contribution::$partnerToken` künftig `$addresseeToken`, weil die Stage dafür sowieso geöffnet wird.

**Files:**
- Modify: `app/Discussion/Values/Contribution.php`, `app/Discussion/Stages/Speak.php:22-98`, `app/Discussion/Stages/PersistMessage.php:13-31`, `resources/views/prompts/speak.blade.php`, `app/Services/ProjectTransfer/ProjectExport.php:57`, `app/Services/ProjectTransfer/ProjectImporter.php:72`, `resources/views/livewire/debug/job-debug-panel.blade.php:194-197`
- Test: `tests/Unit/Discussion/SpeakTest.php`, `tests/Unit/Discussion/PersistMessageTest.php`

**Interfaces:**
- Consumes: `App\Discussion\Values\Contribution` und `App\Discussion\Values\MemoryView` aus Task 1
- Produces: `Contribution::__construct(string $text, ?string $addresseeToken, Completion $completion)` — drei Parameter statt vier, der Paartyp entfällt, `partnerToken` heißt `addresseeToken`. `Speak::MARKER_CONTROL` bleibt unverändert. `Speak::PAIR_TYPES` existiert nicht mehr.

- [ ] **Step 1: Die Tests auf den Zielzustand umschreiben**

`tests/Unit/Discussion/SpeakTest.php` — die Zeile `use App\Models\Message;` entfernen und die drei betroffenen Testmethoden ersetzen:

```php
    public function test_splits_the_visible_text_from_the_control_trailer(): void
    {
        FakeAgents::always(SpeakAgent::class, "Bob, woher nimmst du diese Zahl?\n---STEUERUNG---\nADRESSAT: E{$this->bob->id}");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Bob, woher nimmst du diese Zahl?', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->addresseeToken);
    }

    public function test_a_leftover_pair_type_line_is_ignored(): void
    {
        // Ein Modell, das noch das alte Format liefert, darf keinen Fehler auslösen:
        // der Trailer wird nur nach ADRESSAT durchsucht, alles andere fällt weg.
        FakeAgents::always(SpeakAgent::class, "Text.\n---STEUERUNG---\nADRESSAT: E{$this->bob->id}\nPAARTYP: Frage→Antwort");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Text.', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->addresseeToken);
    }

    public function test_a_missing_trailer_degrades_to_a_plenum_contribution(): void
    {
        FakeAgents::always(SpeakAgent::class, 'Ich sehe das anders.');

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Ich sehe das anders.', $contribution->text);
        $this->assertNull($contribution->addresseeToken);
    }

    public function test_an_unknown_addressee_is_dropped(): void
    {
        FakeAgents::always(SpeakAgent::class, "Text.\n---STEUERUNG---\nADRESSAT: E999");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertNull($contribution->addresseeToken);
    }

    public function test_an_empty_visible_text_is_a_parse_failure(): void
    {
        FakeAgents::always(SpeakAgent::class, "---STEUERUNG---\nADRESSAT: none");

        $this->expectException(ParseFailure::class);

        $this->speak($this->payload());
    }
```

`test_unknown_addressees_and_pair_types_are_dropped` entfällt dabei — `test_an_unknown_addressee_is_dropped` tritt an ihre Stelle. `test_the_prompt_carries_a_proposal_when_the_speaker_made_one` bleibt unverändert.

`tests/Unit/Discussion/PersistMessageTest.php` — die Zeile `use App\Models\Message;` entfernen und beide Testmethoden ersetzen:

```php
    public function test_saves_the_contribution_with_its_addressee(): void
    {
        $project = Project::factory()->create();
        [$alice, $bob] = Expert::factory()->count(2)->create()->all();
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);
        $log = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'running', 'started_at' => now()]);

        $payload = new TurnPayload($project, 1, $log->id);
        $payload->select(new Selection($alice, 'RoundRobinSelector'));
        $payload->contribute(new Contribution('Bob, wie meinst du das?', "E{$bob->id}", $this->completion()));

        (new PersistMessage)->handle($payload, fn (TurnPayload $p) => $p);

        $message = $payload->message()->fresh();

        $this->assertSame('Bob, wie meinst du das?', $message->content);
        $this->assertSame($alice->id, $message->expert_id);
        $this->assertSame($log->id, $message->job_log_id);
        $this->assertTrue($message->adjacencyPartner->is($bob));
    }

    public function test_a_plenum_contribution_gets_no_addressee(): void
    {
        $project = Project::factory()->create();
        $alice = Expert::factory()->create();
        $project->addContributingExpert($alice);

        $payload = new TurnPayload($project, 1);
        $payload->select(new Selection($alice, 'RoundRobinSelector'));
        $payload->contribute(new Contribution('Ich sehe das anders.', null, $this->completion()));

        (new PersistMessage)->handle($payload, fn (TurnPayload $p) => $p);

        $this->assertNull($payload->message()->fresh()->adjacency_partner_id);
    }
```

`adjacencyPartner` und `adjacency_partner_id` stehen hier noch — die Relation wird erst in Task 3 umbenannt. So bleibt jede Aufgabe für sich grün.

- [ ] **Step 2: Nachweisen, dass die Tests fehlschlagen**

```bash
php artisan test --filter='SpeakTest|PersistMessageTest' 2>&1 | tail -25
```

Erwartet: Fehlschläge. `Contribution::__construct()` erwartet noch vier Argumente und bekommt drei; `$contribution->addresseeToken` existiert nicht.

- [ ] **Step 3: `Contribution` auf drei Felder bringen**

`app/Discussion/Values/Contribution.php` vollständig:

```php
<?php

namespace App\Discussion\Values;

/** The public contribution of the turn; $text is what gets counted. */
final readonly class Contribution
{
    public function __construct(
        public string $text,
        public ?string $addresseeToken,
        public Completion $completion,
    ) {}
}
```

- [ ] **Step 4: `Speak` vom Paartyp befreien**

In `app/Discussion/Stages/Speak.php`: die Konstante `PAIR_TYPES` samt dem `use App\Models\Message;` darüber entfernen, die Methode `pairType()` entfernen, `partnerToken()` in `addresseeToken()` umbenennen und `'pair_types' => self::PAIR_TYPES,` aus den Prompt-Daten streichen. Die beiden geänderten Methoden danach:

```php
    private function prompt(TurnPayload $payload, Expert $speaker): string
    {
        return $this->prompts->render('prompts.speak', [
            'expert' => $speaker,
            'project' => $payload->project,
            'memory' => $this->memory->viewFor($payload->project, $speaker),
            'proposal' => $payload->thoughtOf($speaker)?->proposal,
            'marker_control' => self::MARKER_CONTROL,
        ] + $this->prompts->participants($payload->project));
    }

    /** The visible prose is never parsed; only the trailer after the marker is. */
    private function parse(Completion $completion, Project $project): Contribution
    {
        $position = mb_strpos($completion->text, self::MARKER_CONTROL);

        $text = trim($position === false ? $completion->text : mb_substr($completion->text, 0, $position));
        $trailer = $position === false ? '' : mb_substr($completion->text, $position + mb_strlen(self::MARKER_CONTROL));

        if ($text === '') {
            throw new ParseFailure('Speak: the answer has no visible contribution.');
        }

        return new Contribution($text, $this->addresseeToken($trailer, $project), $completion);
    }

    private function addresseeToken(string $trailer, Project $project): ?string
    {
        if (! preg_match('/ADRESSAT:\s*(\S+)/u', $trailer, $match)) {
            return null;
        }

        return $project->contributorByPromptId($match[1]) instanceof Expert ? $match[1] : null;
    }
```

- [ ] **Step 5: `PersistMessage` setzt keinen Vorgabewert mehr**

In `app/Discussion/Stages/PersistMessage.php` die Zeile

```php
        $message->adjacency_pair_type = $contribution->pairType ?? Message::PAIR_BEITRAG_DISKUSSION;
```

löschen, `$contribution->partnerToken` zu `$contribution->addresseeToken` ändern und `use App\Models\Message;` entfernen. Die Methode danach:

```php
    public function handle(TurnPayload $payload, Closure $next)
    {
        $contribution = $payload->contribution();

        $message = $payload->project->addMessage($contribution->text, $payload->selection()->speaker);
        $message->job_log_id = $payload->jobLogId;

        $addressee = $payload->project->contributorByPromptId($contribution->addresseeToken);

        if ($addressee instanceof Expert) {
            $message->adjacencyPartner()->associate($addressee);
        }

        $message->save();
        $payload->persisted($message);

        return $next($payload);
    }
```

Der stille Vorgabewert verschwindet damit mit: bisher wurde ein fehlender Paartyp unbemerkt zu `Beitrag→Diskussion`.

- [ ] **Step 6: Tests laufen lassen**

```bash
php artisan test --filter='SpeakTest|PersistMessageTest' 2>&1 | tail -10
```

Erwartet: grün.

- [ ] **Step 7: Den Prompt kürzen**

In `resources/views/prompts/speak.blade.php` den `STEUERUNG`-Abschnitt am Dateiende ersetzen. Aus

```
{{ $marker_control }}
ADRESSAT: <Token des Experten, den dein Beitrag anspricht, z. B. E7 — oder "none", wenn du ans Plenum sprichst>
PAARTYP: <einer von: {{ implode(' | ', $pair_types) }}>
- ADRESSAT ist NUR ein Experten-Token aus der TEILNEHMER-Liste oder "none". Niemals ein Nutzer, niemals ein Name.
- PAARTYP: "Frage→Antwort" wenn dein Beitrag eine direkte Frage stellt, "Ansprache→Reaktion" wenn er auf eine Ansprache reagiert, "Synthese→Diskussion" wenn du verdichtest/zusammenführst, sonst "Beitrag→Diskussion".
- Die Tokens und dieser Block erscheinen ausschließlich hier, niemals im sichtbaren Beitrag darüber.
```

wird

```
{{ $marker_control }}
ADRESSAT: <Token des Experten, den dein Beitrag anspricht, z. B. E7 — oder "none", wenn du ans Plenum sprichst>
- ADRESSAT ist NUR ein Experten-Token aus der TEILNEHMER-Liste oder "none". Niemals ein Nutzer, niemals ein Name.
- Die Tokens und dieser Block erscheinen ausschließlich hier, niemals im sichtbaren Beitrag darüber.
```

Der Abschnitt `=== REAKTIONS-TYPEN (Präferenzorganisation) ===` weiter oben bleibt unangetastet. Er steuert, wie ein Agent formuliert, und ist kein geparstes Feld.

- [ ] **Step 8: Export, Import und Debug-Panel bereinigen**

In `app/Services/ProjectTransfer/ProjectExport.php` die Zeile `'adjacency_pair_type' => $m->adjacency_pair_type,` löschen.

In `app/Services/ProjectTransfer/ProjectImporter.php` die Zeile `$msg->adjacency_pair_type = $m['adjacency_pair_type'] ?? null;` löschen.

In `resources/views/livewire/debug/job-debug-panel.blade.php` den Block

```blade
                                    @if ($msg->adjacency_pair_type)
                                        <span class="text-zinc-400">·</span>
                                        <span class="font-mono text-zinc-500">{{ $msg->adjacency_pair_type }}</span>
                                    @endif
```

löschen. Der `@if ($msg->adjacency_partner_id)`-Block direkt darunter bleibt stehen.

- [ ] **Step 9: Volle Suite und Stil**

```bash
php artisan test 2>&1 | tail -5
vendor/bin/pint
grep -rn 'pairType\|PAIR_\|PAARTYP\|pair_types' app resources tests
```

Erwartet: Suite grün, Pint sauber. Der `grep` findet nur noch `adjacency_pair_type` in der Migration (fällt in Task 3) und nichts weiter.

- [ ] **Step 10: Commit**

```bash
git add app resources tests
git commit -m "Stop asking the speaker to label its own speech act

adjacency_pair_type held one of four labels the speaking model reported
about itself. No target measure of the study reads it: turn share,
conversation share, their ratio, Gini, entropy, the pipeline-internal
signals and the adoption of the titled agent's position all get by
without it. As a model's self-report about its own speech act it would
not be a solid datum anyway.

What it cost: a prompt section, a parser, four constants and a silent
default -- a missing pair type quietly became Beitrag->Diskussion.

Contribution loses the field and renames partnerToken to addresseeToken.
The column stays for now and runs empty; the migration follows.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Aus dem Morph wird `addressee`

`nullableMorphs('adjacency_partner')` konnte auf `Expert` oder `User` zeigen. Seit dem Rückbau gibt es keinen Nutzerbeitrag mehr — der Chat hat keinen Composer, und `Speak::addresseeToken()` verwirft ein `U`-Token. Zwei Spalten plus Index für eine Relation mit genau einem möglichen Zieltyp sind Aufwand ohne Gegenwert.

Diese Aufgabe ist nicht weiter teilbar: die Spalte wechselt den Namen, und alles, was sie liest oder schreibt, muss im selben Commit mitgehen.

**Files:**
- Modify: `database/migrations/2025_05_30_152442_create_messages_table.php:22-26`, `app/Models/Message.php`, `app/Discussion/Stages/PersistMessage.php`, `app/Events/MessageGenerated.php:34-51`, `app/Services/ProjectTransfer/ProjectExport.php:57-61`, `app/Services/ProjectTransfer/ProjectImporter.php:72-85`, `resources/views/components/projects/chat-message.blade.php:12-20,50`, `resources/views/components/projects/addressed-chip.blade.php`, `resources/views/livewire/debug/job-debug-panel.blade.php`, `CLAUDE.md:112,113,160`
- Test: `tests/Unit/Discussion/PersistMessageTest.php`, `tests/Unit/MessageGeneratedPayloadTest.php`

**Interfaces:**
- Consumes: `Contribution::$addresseeToken` aus Task 2
- Produces: `messages.addressee_expert_id` (nullable, FK auf `experts`, `nullOnDelete`), `Message::addressee(): BelongsTo` (Zielklasse `Expert`, Fremdschlüssel `addressee_expert_id`), Event-Nutzlast `MessageGenerated::broadcastWith()` mit den Schlüsseln `project_id`, `message_id`, `expert_id`, `addressed_expert_id`, `next_turn_delay_seconds` — `addressed_user_id` entfällt. Exportschlüssel `addressee_expert_id`. `Message::adjacencyPartner()`, `Message::PAIR_*`, `messages.adjacency_pair_type` und `messages.adjacency_partner_type/_id` existieren nicht mehr.

- [ ] **Step 1: Die Tests auf den Zielzustand umschreiben**

In `tests/Unit/Discussion/PersistMessageTest.php` die beiden Zusicherungen auf die neue Relation umstellen:

```php
        $this->assertTrue($message->addressee->is($bob));
```

und

```php
        $this->assertNull($payload->message()->fresh()->addressee_expert_id);
```

In `tests/Unit/MessageGeneratedPayloadTest.php`: `test_payload_carries_user_addressee` **vollständig löschen** — der Fall kann nicht mehr eintreten. In `test_payload_contains_speaker_and_addressed_ids` die Verknüpfung und die Zusicherung anpassen:

```php
        $message = $project->addMessage('Hallo Bob.', $alice);
        $message->addressee()->associate($bob);
        $message->save();

        $payload = (new MessageGenerated($project->id, $message->id, 5))->broadcastWith();

        $this->assertSame($project->id, $payload['project_id']);
        $this->assertSame($message->id, $payload['message_id']);
        $this->assertSame($alice->id, $payload['expert_id']);
        $this->assertSame($bob->id, $payload['addressed_expert_id']);
        $this->assertArrayNotHasKey('addressed_user_id', $payload);
        $this->assertSame(5, $payload['next_turn_delay_seconds']);
```

In `test_payload_handles_missing_message` die Zeile `$this->assertNull($payload['addressed_user_id']);` durch

```php
        $this->assertArrayNotHasKey('addressed_user_id', $payload);
```

ersetzen.

- [ ] **Step 2: Nachweisen, dass die Tests fehlschlagen**

```bash
php artisan test --filter='PersistMessageTest|MessageGeneratedPayloadTest' 2>&1 | tail -25
```

Erwartet: Fehlschläge wegen `Call to undefined method App\Models\Message::addressee()`.

- [ ] **Step 3: Die Migration ändern**

In `database/migrations/2025_05_30_152442_create_messages_table.php` den Block

```php
            // Adjacency metadata: the typed pair label plus the polymorphic
            // addressee (expert or user). A User partner is a hand-back; the
            // partner is set from the SPEAK output or the moderator hand-off.
            $table->string('adjacency_pair_type', 50)->nullable();
            $table->nullableMorphs('adjacency_partner');
```

ersetzen durch

```php
            // Whom this contribution addresses. Only a contributing expert can be:
            // the chat has no composer, and Speak drops a U token. Null means the
            // turn spoke to the group.
            $table->foreignId('addressee_expert_id')->nullable()->constrained('experts')->nullOnDelete();
```

Kein zweite Migration und kein `down()`-Sonderfall: es gibt keine Daten, die erhalten bleiben müssen (siehe Global Constraints).

- [ ] **Step 4: Das Modell umstellen**

In `app/Models/Message.php` die vier `PAIR_*`-Konstanten samt Kommentar löschen, `use Illuminate\Database\Eloquent\Relations\MorphTo;` entfernen und `adjacencyPartner()` ersetzen:

```php
    /**
     * The contributing expert this message addresses, if any. Set from the SPEAK
     * output; null means the turn spoke to the group. Only an expert can be
     * addressed — users cannot contribute to a run.
     */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(Expert::class, 'addressee_expert_id');
    }
```

- [ ] **Step 5: `PersistMessage` und das Event umstellen**

In `app/Discussion/Stages/PersistMessage.php` die eine Zeile:

```php
            $message->addressee()->associate($addressee);
```

In `app/Events/MessageGenerated.php` die Methode `broadcastWith()` ersetzen und die dann unbenutzten Importe `use App\Models\Expert;` und `use App\Models\User;` entfernen:

```php
    public function broadcastWith(): array
    {
        $message = $this->messageId ? Message::find($this->messageId) : null;

        return [
            'project_id' => $this->projectId,
            'message_id' => $this->messageId,
            'expert_id' => $message?->expert_id,
            'addressed_expert_id' => $message?->addressee_expert_id,
            'next_turn_delay_seconds' => $this->nextTurnDelaySeconds,
        ];
    }
```

`addressed_user_id` wird nirgends gelesen — kein Blade, kein Livewire-Listener, kein JavaScript greift darauf zu. Der Schlüssel fällt daher ganz, statt auf `null` stehen zu bleiben.

- [ ] **Step 6: Tests laufen lassen**

```bash
php artisan test --filter='PersistMessageTest|MessageGeneratedPayloadTest' 2>&1 | tail -10
```

Erwartet: grün.

- [ ] **Step 7: Export und Import umstellen**

In `app/Services/ProjectTransfer/ProjectExport.php` die drei Zeilen

```php
                    // Polymorphic addressee, flattened: the expert id (re-linkable)
                    // or a user flag (reassigned to the importing owner).
                    'adjacency_partner_expert_id' => $m->adjacency_partner_type === Expert::class ? $m->adjacency_partner_id : null,
                    'adjacency_partner_is_user' => $m->adjacency_partner_type === User::class,
```

ersetzen durch

```php
                    'addressee_expert_id' => $m->addressee_expert_id,
```

In `app/Services/ProjectTransfer/ProjectImporter.php` den Block

```php
                // Re-link the polymorphic addressee: an expert only if it survived
                // re-linking; a user hand-off is reassigned to the importing owner
                // (the export carries no stable user id), mirroring user messages.
                $apExpert = isset($m['adjacency_partner_expert_id']) ? (int) $m['adjacency_partner_expert_id'] : null;
                if ($apExpert !== null && in_array($apExpert, $existingIds, true)) {
                    $msg->adjacency_partner_type = Expert::class;
                    $msg->adjacency_partner_id = $apExpert;
                } elseif (! empty($m['adjacency_partner_is_user'])) {
                    $msg->adjacency_partner_type = User::class;
                    $msg->adjacency_partner_id = $owner->id;
                }
```

ersetzen durch

```php
                // Re-link the addressee, but only to an expert that survived
                // re-linking. No support for the old export format: a file from
                // before this change imports without addressees.
                $addresseeId = isset($m['addressee_expert_id']) ? (int) $m['addressee_expert_id'] : null;
                if ($addresseeId !== null && in_array($addresseeId, $existingIds, true)) {
                    $msg->addressee_expert_id = $addresseeId;
                }
```

Werden `use App\Models\Expert;` oder `use App\Models\User;` danach nirgends mehr gebraucht, entfernt Pint sie in Step 9 selbst — der `no_unused_imports`-Fixer gehört zum Laravel-Preset und ist empirisch geprüft. Also nicht von Hand nachräumen, sondern `vendor/bin/pint` laufen lassen und das Ergebnis mitcommitten.

- [ ] **Step 8: Die Ansichten umstellen**

In `resources/views/components/projects/chat-message.blade.php` den `@php`-Block oben:

```php
    $sender = $msg->sender();
    $isOwn  = $msg->isCurrUser();

    // Whom this message speaks to. Always a contributing expert; a message that
    // addresses its own sender shows no chip.
    $addressed = $msg->addressee;
    if ($addressed !== null && $addressed->id === $msg->expert_id) {
        $addressed = null;
    }

    $renderedContent = Markdown::parse($msg->content);
```

und den Aufruf in Zeile 50:

```blade
                    <x-projects.addressed-chip :addressed="$addressed" />
```

In `resources/views/components/projects/addressed-chip.blade.php` die `isExpert`-Prop entfernen — sie steuerte nur den Namen eines Datenattributs, das nirgends gelesen wird:

```blade
@props([
    'addressed',
])

{{-- "speaks to" indicator: arrow plus the addressed expert. Display-only; the
     memory flyout opens from the sender avatar. --}}
<span class="relative inline-flex min-w-0 items-center gap-1.5" data-addressed-expert-id="{{ $addressed->id }}">
```

Der Rest der Datei bleibt unverändert.

In `resources/views/livewire/debug/job-debug-panel.blade.php`:

```blade
                                    @if ($msg->addressee_expert_id)
                                        <span class="text-zinc-400">→</span>
                                        <span class="text-zinc-500">{{ $msg->addressee?->name }}</span>
                                    @endif
```

- [ ] **Step 9: Volle Suite, frische Datenbank, Stil**

```bash
php artisan test 2>&1 | tail -5
php artisan migrate:fresh --force && php artisan dev:build-suite
vendor/bin/pint
grep -rn 'adjacency\|adjacencyPartner\|PAIR_' app resources tests database
```

Erwartet: Suite grün, Migration und Seeder laufen durch, Pint sauber, `grep` ohne Ausgabe.

- [ ] **Step 10: Im Browser nachsehen**

`composer dev` starten, eines der Demo-Projekte öffnen, die Diskussion einige Turns laufen lassen. Zu prüfen:

1. Eine Nachricht mit Adressat zeigt den Pfeil-Chip mit dem richtigen Namen.
2. Eine Nachricht ans Plenum zeigt keinen Chip.
3. Im Debug-Report (`/debug/jobs`) steht bei den Nachrichten der Adressat und kein Paartyp mehr.

Das ist der einzige Schritt, den kein Test abdeckt: die Blade-Änderungen in Step 8 hängen an einer Relation, deren Fehlen sich zur Laufzeit als stilles `null` zeigt, nicht als Fehler.

- [ ] **Step 11: CLAUDE.md nachziehen**

Drei Stellen:

`### Turn pipeline`, Punkt 3 (`Speak`): `and a trailer parsed for ADRESSAT/PAARTYP` → `and a trailer parsed for ADRESSAT`.

Punkt 4 (`PersistMessage`): `resolves the addressee token back to a contributor via contributorByPromptId(), sets adjacency_partner/adjacency_pair_type` → `resolves the addressee token back to a contributing expert via contributorByPromptId() and sets addressee_expert_id`.

`### Data model notes`, erster inhaltlicher Punkt:

```markdown
- `Message` belongs to an expert *or* a user; `addressee` is a `belongsTo(Expert)` over `addressee_expert_id` — whom the turn speaks to, null for the group. Only an expert can be addressed: the chat has no composer, and `Speak` drops a `U` token. There is no pair-type column; the typed adjacency label was a model's self-report and no target measure of the study read it.
```

- [ ] **Step 12: Commit**

```bash
git add app resources tests database CLAUDE.md
git commit -m "One addressee column instead of a polymorphic pair

adjacency_partner could point at an Expert or a User. Since the rollback
there is no user contribution: the chat has no composer, and Speak drops
a U token before it ever reaches the database. Two columns plus an index
for a relation with exactly one possible target type is cost without
return.

So: addressee_expert_id, a plain belongsTo, and the event payload loses
addressed_user_id -- nothing read it. The chip loses its isExpert prop,
which only picked the name of a data attribute nobody reads either.

Migration changed in place, not added: nothing here runs in production
and no run has to survive.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Selbstprüfung dieses Plans

**Spec-Abdeckung.** Abschnitt 3.1 der Spec (drei Verschiebungen) → Task 1 Steps 2–5. Abschnitt 3.2 (was bleibt) → als Negativliste in der Einordnungsregel, Task 1 Step 7. Abschnitt 3.3 (betroffene Dateien) → Task 1 Step 5. Abschnitt 3.4 (keine Aliase) → Global Constraints. Abschnitt 3.5 (Abnahme) → Task 1 Steps 1, 6, 7. Abschnitt 4.1 (Paartyp weg) → Task 2 komplett. Abschnitt 4.2 (Morph zu belongsTo) → Task 3 Steps 3–5. Abschnitt 4.3 (Schema) → Task 3 Step 3. Abschnitt 4.4 (Code) → Task 2 Steps 3–5, 7, 8 und Task 3 Steps 4, 5, 7, 8. Abschnitt 4.5 (Sprachdateien: nichts zu tun) → keine Aufgabe, richtig so. Abschnitt 4.6 (Tests) → Task 2 Step 1, Task 3 Step 1; der dort begründete Verzicht auf einen Migrationstest ist eingehalten. Abschnitt 4.7 (Abnahme) → Task 3 Steps 9, 10. Abschnitt 5 (nicht Teil) → Global Constraints, letzter Punkt.

**Typkonsistenz.** `Contribution::$addresseeToken` wird in Task 2 Step 3 definiert und in Task 2 Steps 4, 5 sowie Task 3 Step 5 unter genau diesem Namen benutzt. `Message::addressee()` wird in Task 3 Step 4 definiert und in Steps 1, 5, 8 benutzt. `messages.addressee_expert_id` wird in Step 3 angelegt und in Steps 1, 5, 7, 8 gelesen. `adjacencyPartner` steht bewusst noch in Task 2 (Steps 1, 5) und verschwindet in Task 3 — dieser Übergang ist an beiden Stellen als gewollt vermerkt.

**Verbleibendes Risiko.** Die Blade-Änderungen aus Task 3 Step 8 deckt kein Test ab; dafür steht Step 10. Wenn beim Ausführen auffällt, dass `chat-message.blade.php` ohnehin einen Test verdient, gehört der in eine eigene Aufgabe und nicht in diesen Plan — er wäre eine Erweiterung der Testabdeckung, keine Voraussetzung dieser Änderung.
