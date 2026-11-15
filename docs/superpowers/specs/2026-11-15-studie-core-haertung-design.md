# Core-Härtung vor der Hauptstudie (Teilprojekt A)

Stand 15.11.2026. Erstes von sechs Teilprojekten der Studienvorbereitung (A Core-Härtung, B Planung,
C Steuerung, D Überwachung, E Export/Auswertung, F Betrieb). Herkunft der Punkte: Maßnahmenkatalog in
`2026-11-12-studien-engine-brainstorming-notizen.md` (M4, M5, M9, M10, M11).

## Ziel

Vier Eigenschaften, die jeder Lauf der Hauptstudie braucht und die heute fehlen:

1. Es ist belegbar, mit welchem Code und welchen Texten ein Lauf gefahren ist.
2. Derselbe Fehler zählt in jeder Pipeline gleich.
3. Ein stiller Modellwechsel beendet den Lauf, statt in den Daten zu landen.
4. Was ein gestarteter Lauf gelesen hat (Persona, Thema), lässt sich nachträglich nicht mehr ändern.

Alle Änderungen sind allgemein und liegen im Core. Der Core nennt das Studienmodul weiterhin nicht.

## Nicht Teil dieses Teilprojekts

- **M12 (`focus seat` statt `focus persona`)** ist keine Core-Änderung: Der Planer (B) setzt den Projekt-Tag
  `focus seat`, der Export (E) trägt ihn. Die Statistik-Vorlagen lesen weiter den Persona-Tag und gelten damit
  nur für die Pilotreihen; die Hauptstudie wird über den Export ausgewertet.
- **Pagination von Sidebar und Listen** (offen seit Entscheidung 16).
- Pausen-Flag, Breaker, ein Job pro Turn, Worker-Zahl (M6–M8, Teilprojekt C).

## 1. Provenienz in `run_config` (M4)

**Heute:** `TurnRunner::snapshotRunConfig()` friert Pipeline, Stages, Modell, System-Prompt, Sichtbarkeiten und
je Sitz Name und Attributzeilen ein. Es fehlen der Code-Stand, die übrigen Prompt-Templates, die Beschreibung der
Persona und das Thema.

**Neu**, im selben Snapshot beim ersten Turn:

```php
'provenance' => [
    'commit'       => 'd17c8b8…',   // git rev-parse HEAD, null wenn kein Git-Checkout
    'dirty'        => false,         // git status --porcelain nicht leer; null wenn commit null
    'laravel_ai'   => '1.0.4',       // Composer\InstalledVersions
    'endpoint'     => 'https://…',   // Basis-URL des Providers aus config('ai.providers'), null beim SDK-Standard
    'prompt_views' => 'sha256…',     // ein Hash über alle Dateien unter resources/views/prompts (Pfad + Inhalt, sortiert)
],
'topic' => $project->description,
'cast'  => [[ …, 'description' => $expert->description ]],
```

- Neue Klasse `App\Discussion\Support\CodeProvenance` mit `toArray()`. Commit, Dirty-Flag und Hash werden je
  Prozess einmal ermittelt (ein Worker legt viele Läufe an).
- Kein Git vorhanden: `commit` und `dirty` sind null, der Lauf startet trotzdem. Dass eine Welle nur mit
  sauberem Commit startet, prüft der Preflight in B, nicht der Core.
- Ältere Läufe haben die Schlüssel nicht. Kein Leser darf sie voraussetzen.
- Die Prompts lesen Beschreibung und Thema weiter live. Der Snapshot ist der Beleg, Abschnitt 4 die Garantie,
  dass live und Snapshot gleich bleiben.
- `runs.csv` bekommt die Spalten `commit` und `dirty` in Teilprojekt E.

## 2. Gleiche Fehlerklassen im Kindprozess (M5)

**Heute:** Scheitert ein Aufruf in einem `ParallelPrompts`-Kindprozess, baut Laravels `ProcessDriver` die
Exception im Elternprozess aus Klassenname und Nachricht neu (`ProcessDriver.php:58`). Für `RequestException`
schlägt das fehl (`TypeError`), der HTTP-Status geht verloren. Folge: Ein 500er ist im Orchestrator
`interrupted`, in Score und Blind Quality `failed`.

**Neu:** Die Aufgabe scheitert nicht mehr im Kind, sondern meldet ihr Scheitern als Wert.

- `ParallelPrompts::run()` umhüllt jede Aufgabe. Die Hülle fängt jedes `Throwable` und gibt statt des
  Ergebnisses ein `ChildFailure` zurück: Klasse, Nachricht, HTTP-Status (falls vorhanden),
  `transient` (`TransientFailure::is()`, im Kind ausgewertet, wo die echte Exception noch existiert) und
  `abortsRun` (`instanceof AbortsRun`). Nur Skalare, wie `Completion`.
- Der Elternprozess wirft für das erste `ChildFailure` der Runde:
  - `PromptTaskFailed` (trägt Klasse, Status, `transient`), oder
  - `PromptTaskAborted implements AbortsRun`, wenn `abortsRun` gesetzt ist.
- `TransientFailure::is()` erkennt zusätzlich `PromptTaskFailed` mit `transient === true`.
- Derselbe Weg gilt für eine einzelne Aufgabe, den sync-Treiber und den Fallback `oneAfterAnother`, damit sich
  Tests und Betrieb nicht unterscheiden.

**Ergebnis:** 5xx, 429, Verbindungsfehler und Timeout sind in jeder Pipeline `interrupted`. Ein 4xx ist in jeder
Pipeline `failed`, und `job_logs.error` nennt die echte Klasse und den Status statt `TypeError`.

**Für den Methodenteil:** Die Pilotreihen wurden noch nach der alten Regel gezählt; ihre Fehlerraten sind mit
denen der Hauptstudie nicht vergleichbar.

**Im Plan zu prüfen:** dass `SerializableClosure` eine Hülle serialisiert, die die Aufgabe als Closure einfängt
(sonst wird die Hülle eine statische Methode, die die Aufgaben selbst aufrufen).

## 3. Modell-Drift beendet den Lauf (M9)

**Heute:** `GuardAgainstModelDrift` schreibt einen Log-Eintrag, der Turn läuft weiter. `JevClient` hält es mit
der Jev-Version genauso.

**Neu:** Beide werfen `App\Discussion\ModelDrifted implements AbortsRun` (angefragtes und antwortendes Modell in
der Nachricht). Der vorhandene `AbortsRun`-Weg greift: `TurnRunner` schließt den Turn als `failed` und wirft
weiter, `project:run` meldet „Aborted“, `RunStudyProject` gibt den Lauf auf. Über den Weg aus Abschnitt 2 gilt
das auch im Kindprozess.

- Der Aufruf selbst steht mit `model_served` in `prompt_logs`, wie bisher.
- Ein Datums-Suffix bleibt erlaubt (`ModelIdentity`, unverändert).
- Kein Schalter: Ein Lauf mit zwei Modellen ist in keiner Umgebung brauchbar.
- Dass danach die ganze Welle pausiert, ist Sache des Breakers (C).

**Im Plan zu prüfen:** dass eine Exception aus einem Listener von `StepCompleted` bis zum `TurnRunner`
durchkommt und vom SDK nicht geschluckt wird.

## 4. Sperren, was ein gestarteter Lauf gelesen hat (M11)

**Persona.** `Expert::isLocked()` ist wahr, sobald der Experte in einem Projekt mit `run_config` sitzt oder ein
`job_logs`-Eintrag ihn nennt (wer sprach und den Lauf verließ).

Für einen gesperrten Experten gilt:

| Aktion | Verhalten |
|---|---|
| Name oder Beschreibung ändern | `PersonaLocked` (Model-Hook `updating`) |
| Attributwert setzen oder ändern | `PersonaLocked` (`setAttributeValue`, `syncAttributeValues`) |
| Löschen | `PersonaLocked` (Model-Hook `deleting`) |
| Tags ändern | erlaubt: Tags erreichen kein Modell und werden für die Auswertung gebraucht |
| Avatar ändern | erlaubt |

- `ExpertPolicy::update` und `delete` verneinen für gesperrte Experten. Der Editor öffnet dann im vorhandenen
  Nur-Lese-Modus mit einem Hinweis, warum. Tags bleiben für Admins über einen eigenen Speicherweg editierbar.
- `init:experts` überspringt einen gesperrten Experten mit Warnung, statt abzubrechen. Der Bundle-Import
  ändert vorhandene Experten ohnehin nie.
- Die Sperre sitzt im Model, damit Console und Import sie nicht umgehen. Ein Massen-`delete()` an Eloquent
  vorbei umgeht sie; im Code gibt es keinen.

**Folge, bewusst:** Jede Persona der Pilotreihen ist ab dem Deploy gesperrt. Wer eine ändern will, legt eine
neue an. Eine „Kopie anlegen“-Funktion gibt es nicht.

**Thema.** `EditProject` weist eine Änderung der Beschreibung zurück, sobald `run_config` existiert, wie heute
schon bei der Attribut-Sichtbarkeit. Der Titel bleibt änderbar (er steht in keinem Prompt).

## 5. Setzbare uuid (M10)

`HasPublicUuid` behält eine vor dem Speichern gesetzte uuid schon heute (`??=`); nur die Massenzuweisung
verwirft sie. Einzige Änderung: `OpenProject::handle()` bekommt einen optionalen Parameter `?string $uuid`, den
es per `forceFill` setzt. Experten legt der Planer (B) direkt mit `forceFill` an, dafür braucht es keine
Core-Änderung.

## Tests

- `TurnRunnerTest`: Der Snapshot enthält `provenance`, `topic` und `cast[].description`; ein Lauf mit älterem
  Snapshot ohne diese Schlüssel läuft weiter.
- `CodeProvenanceTest`: Der Hash ändert sich, wenn sich eine Prompt-Datei ändert; ohne Git sind `commit` und
  `dirty` null.
- `ParallelPromptsTest`: Aus einer `RequestException` mit 500 wird `PromptTaskFailed` mit `transient`, aus
  einer mit 400 eine ohne; eine `AbortsRun`-Exception wird `PromptTaskAborted`.
- Ein Turn-Test je Pipeline-Art: Ein 500er im Kind ergibt `interrupted` in Score wie im Orchestrator.
- `GuardAgainstModelDriftTest`, `JevClientTest`: Abweichung wirft `ModelDrifted`; der Turn ist `failed`,
  `project:run` bricht ab.
- `ExpertLockTest`: jede Zeile der Tabelle aus Abschnitt 4, dazu `init:experts` und die Policy.
- `EditProjectTest`: Die Beschreibung ist nach dem ersten Turn gesperrt.
- `OpenProjectTest`: Eine übergebene uuid wird übernommen.
- `PromptGoldenTest` und `TeamSelectorGoldenTest` bleiben unverändert grün (kein Prompt ändert sich).

## Doku

`CLAUDE.md`: Abschnitte Turn pipeline (Snapshot), Failure diagnosis (der `TypeError` entfällt), LLM layer
(Drift), Authorization (`ExpertPolicy`), Study batches (`TransientFailure`). `docs/pipeline.puml` ist nicht
betroffen.
