# Steuerung der Studie (Teilprojekt C)

Stand 15.11.2026. Teilprojekt C der Studienvorbereitung (Maßnahmen M6, M7, M8 aus `2026-11-12-studien-engine-brainstorming-notizen.md`). Voraussetzung ist Teilprojekt A (`2026-11-15-studie-core-haertung-design.md`). C ändert **keinen Core-Code**, alles liegt unter `app/Study`.

## Ziel

Sechs Worker auf dem VPS (2 vCPU, 4 GB) arbeiten tagelang ohne Aufsicht 3.072 Läufe ab. Dabei gilt:

- Ein Neustart oder Stopp kostet höchstens einen Turn.
- Ein globaler Fehler (Key, Guthaben, Modellwechsel, Ausfall) hält die ganze Studie an, bevor sie leer läuft.
- Nichts, was zur Pause führt, macht einen Lauf zum aufgegebenen Lauf.
- Der Zustand liegt in bestehenden Quellen (`job_logs`, Projekt-Tags, `failed_jobs`) und einer Pausen-Datei, nicht in einer neuen Tabelle.

## Nicht Teil dieses Teilprojekts

- Ersatzlauf anlegen (Teilprojekt B, `study:plan`). C markiert nur `superseded`.
- Alarme, Discord, Dashboard-Knöpfe (Teilprojekt D). C liefert `PauseFlag`, `BreakerTripped` und die Befehle.
- MySQL, Backups, Swap, Log-Rotation (Teilprojekt F). C nennt nur Zahlen, die F bereitstellen muss.
- Kostenbremse. Das Guthaben-Signal (`insufficient_quota`) ist drin, ein Budget in Euro nicht.

## Entscheidungen

| # | Entscheidung | Begründung |
|---|---|---|
| 1 | Ein Job = ein Turn, der Job reiht den Lauf danach selbst neu ein. | Ein Stopp oder Neustart wartet höchstens einen Turn (`TimeoutStopSec` kurz). Pause und Breaker greifen zwischen zwei Turns. |
| 2 | Pause gilt für die ganze Studie, nicht je Welle. | Es gibt eine Queue `study`. Die auslösenden Signale (Key, Guthaben, Drift) sind global. Eine Wellentabelle wäre ein neuer Zustand ohne Nutzen. |
| 3 | Pausen-Flag ist eine Datei (`storage/app/study/pause.json`), nicht der Cache. | `cache:clear` oder `optimize:clear` beim Deploy würde die Pause sonst still aufheben. Alle Worker und die Web-Anwendung laufen auf einem Host. Anlegen mit Modus `x` ist atomar und liefert „neu pausiert ja/nein" ohne Lock. |
| 4 | Zähler des Breakers im Cache, Zustand je Lauf aus `job_logs`. | Ein verlorener Zähler setzt nur auf 0 zurück, das ist harmlos. `job_logs` ist die Wahrheit und überlebt Neustarts. |
| 5 | Jeder `AbortsRun` löst den Breaker sofort aus. | `AbortsRun` heißt „kein späterer Turn kommt hier weiter", und die Konfiguration ist über Läufe gleich. Ein falscher Alarm kostet eine Pause, ein verpasster eine leere Welle. |
| 6 | Streak-Zähler über alle Läufe als Rückfallnetz. Er zählt aufeinanderfolgende Turns ohne Wortmeldung und wird von jedem gesprochenen Turn auf 0 gesetzt. | Eine Fensterrate würde bei normalen Fehlerquoten von einigen Prozent auslösen. Eine Streak von 8 bei 6 Workern ist praktisch nur bei einem Ausfall möglich. |
| 7 | Ein Lauf wird nie aufgegeben, solange die Studie pausiert ist oder der Breaker in diesem Turn auslöste. | Läufe, die in einem Ausfall steckten, sind Opfer und kein Ausschuss. Sonst entstünden `superseded`-Läufe durch einen falschen Key. |
| 8 | Cache-Lock nur um den Turn, Re-Dispatch erst nach Freigabe. | `WithoutOverlapping->dontRelease()` würde den Folgejob verwerfen, wenn ein anderer Worker ihn aufnimmt, bevor der Vorgänger die Middleware verlässt. Die Kette bräche still ab. |
| 9 | `ShouldBeUniqueUntilProcessing` je Projekt. | `study:queue` kann mehrfach laufen, ohne zweite Ketten zu erzeugen. Die Sperre fällt beim Start des Jobs, daher kann der Job seinen Nachfolger einreihen. |
| 10 | Ein `Queue::looping`-Hook hält die Worker an, solange pausiert ist. | Die Worker holen dann keinen Job und wiederholen auch keine, kein Spin. Ohne den Hook würde jeder der bis zu 3.072 Jobs jede Minute neu eingereiht. |

## 1. `RunStudyProject`: ein Job = ein Turn

Konstanten und Konfiguration (alle in `config('study.run')`, Ausschnitt in Abschnitt 6):

- `$timeout = config('study.run.turn_timeout')`, Vorgabe **360 s**. Das Maß ist ein Turn, nicht der Lauf. Der Probelauf misst die P99-Dauer. Liegt sie über 250 s, wird der Wert erhöht (Risiko 2).
- `$tries = 0`. Eine Pause-Freigabe oder eine Wiederaufnahme nach `retry_after` zählt nicht als Versuch. Ein Dauerfehler wird durch `maxExceptions = 1` und die Aufgabe-Regeln unten begrenzt.
- `implements ShouldBeUniqueUntilProcessing`: `uniqueId()` ist die Projekt-ID, `uniqueFor = 3600` (Netz gegen eine verwaiste Sperre nach `queue:clear`).
- Keine Middleware `WithoutOverlapping`.

`handle(TurnRunner, PauseFlag, CircuitBreaker, RunHealth, GiveUp)`:

1. **Pause:** Ist `PauseFlag::active()`, dann `release(pause_poll_seconds)` und Ende. Das ist nur das Rennen, in dem die Pause kurz nach dem Abholen kam. Im Normalfall holt der Looping-Hook (Abschnitt 4) den Job gar nicht ab.
2. **Aufgegeben:** Trägt das Projekt den Tag `superseded`, Ende ohne Re-Dispatch. Das fängt `queue:retry` eines aufgegebenen Laufs ab.
3. **Lock:** `Cache::lock("study-run:{id}", turn_timeout + 60)->get(fn)`. Gelingt er nicht, läuft ein anderer Job für dieses Projekt, dann Ende ohne Re-Dispatch. Der laufende Job hängt seinen Nachfolger an.
4. **Im Lock:**
   - `closeAbandonedTurns()` wie bisher. Der Lock beweist, dass kein Turn lebt.
   - Genau ein `TurnRunner::run()`.
5. **Nach dem Lock:** Der Re-Dispatch fällt nach der Freigabe, siehe Entscheidung 8. Die Behandlung steht in der Tabelle.

| Ergebnis des Turns | Breaker | Folge |
|---|---|---|
| gesprochen (`TurnResult::proceed`) | `turnSpoken()` setzt die Streak auf 0 | sofort neu einreihen (Delay 0) |
| `stop('turn_budget')` | nichts | Ende, kein Re-Dispatch |
| anderer `stop` (z. B. `no_candidates`) | nichts | `fail(RuntimeException)` wie bisher |
| `failed` (nicht transient) | `turnFailed(JobLog)` | Delay 0. Aufgeben, wenn `failedInARow >= max_failed_in_a_row` und der Breaker in diesem Turn nicht ausgelöst hat und nicht pausiert ist |
| `interrupted` oder `jev_outage` | `turnFailed(JobLog)` | Delay `backoff(stalledInARow)`. Aufgeben bei `stalledInARow >= max_stalled_in_a_row` unter denselben Bedingungen |
| `AbortsRun` (aus `run()`) | `aborted($e)` löst sofort aus | Delay `pause_poll_seconds`, kein Aufgeben (der Breaker hat pausiert) |
| transiente Exception außerhalb des Turns (z. B. Broadcast, gesperrte DB beim Öffnen des Logs) | `turnFailed(null)` | Delay `backoff(stalledInARow)` |
| sonstige Exception (Bug) | `unexpected($e)` erhöht die Streak | **weiterwerfen**: der Job scheitert nach `failed_jobs`, die Kette dieses Laufs endet, `study:queue` stellt sie nach der Reparatur wieder her |

`backoff(n) = min(60 · 2^(n−1), 900)` Sekunden, wie heute, aber mit `n` aus `job_logs` und nicht aus `attempts()`, weil jeder Turn ein neuer Job ist.

**Aufgeben** heißt: `GiveUp::mark($project)` setzt den Projekt-Tag `superseded = true`, danach `fail(new RuntimeException("Run {$id} given up: …"))`. Die Zeile in `failed_jobs` bleibt für das Dashboard (aufgegebene Läufe) erhalten. Der Tag ist der dauerhafte Beleg, er überlebt `failed_jobs:prune` und geht über die Tag-Spalten in `runs.csv`.

**Versuchsindex:** Den Tag `attempt` (`1`, `2`, …) setzt der Planer beim Anlegen (B). C liest ihn nicht und legt keinen Ersatzlauf an. `study:queue` überspringt Läufe mit `superseded` (Abschnitt 6).

**Zustand je Lauf aus `job_logs`** (`RunHealth::of(Project)`): die letzten `max_stalled_in_a_row` Zeilen des Projekts, absteigend nach `id`.

- `stalledInARow`: Zeilen in Folge mit `words IS NULL`. Das schließt Interrupted, Failed und die `jev_outage`-Zeile (Status success, keine Wörter) ein.
- `failedInARow`: Zeilen in Folge mit Status `failed` und `words IS NULL`, abgebrochen bei der ersten anderen Zeile.
- Ein Turn, der sprach und danach in `Summarize` scheiterte, hat Wörter und unterbricht beide Ketten. Das ist die Zählregel aus CLAUDE.md („gesprochen = `words` nicht null"), nicht `status`.
- `closeAbandonedTurns()` macht tote Worker zu `interrupted`. Diese Zeilen zählen in `stalledInARow`.

Vorgaben: `max_failed_in_a_row = 10`, `max_stalled_in_a_row = 12`. Die Zahl 10 stammt aus der offenen Präregistrierung (Notizen, „Ersatzlauf nur, wenn …"), sie wird dort festgelegt und hier übernommen. Der bisherige Wert 3 war eine Betriebsschwelle, keine Studienregel.

## 2. Pausen-Flag

`PauseFlag` (Singleton):

- `active(): bool`: `is_file($path)`. Ein `stat` je Job, keine Datenbank.
- `state(): ?PauseState`: liest die Datei. Eine unlesbare Datei gilt als pausiert (sicher) und liefert Grund `unreadable`.
- `pause(string $reason, string $signal, PauseSource $source): bool`: legt `pause.json` mit Modus `x` an (`reason`, `signal`, `source` = `manual|breaker`, `since`). Gibt `true` zurück, nur wenn der Aufruf die Pause *neu* gesetzt hat. Eine zweite Pause lässt den ersten Grund stehen.
- `resume(): void`: löscht die Datei und setzt die Streak des Breakers auf 0. Ohne Reset würde die erste Wiederaufnahme sofort wieder auslösen.

Befehle:

- `php artisan study:pause {reason?}`: setzt `source = manual`. Ohne Grund steht dort „manual".
- `php artisan study:resume`: gibt den alten Zustand aus, löscht die Datei.

Das Dashboard (D) ruft dieselben Methoden. C baut keine Knöpfe.

**Verhalten:**

- Laufende Turns enden normal (höchstens `turn_timeout`). Danach reihen sich die Jobs ein, und kein Worker holt sie ab.
- **Looping-Hook:** `Queue::looping(fn (Looping $e) => $e->connectionName === 'study' && PauseFlag::active() ? false : null)` im `StudyServiceProvider`. Ein Listener, der `false` liefert, lässt `Worker::daemon` den Worker pausieren (`--sleep`, 5 s), statt einen Job zu holen. Das Verhalten ist in Laravel dokumentiert, der Plan prüft es mit einem Test.
- Vorrangig bleibt der Test in Schritt 1 des Jobs, falls ein Job zwischen Hook und Abholen die Pause verpasst.

## 3. Breaker über alle Läufe

`CircuitBreaker` (Singleton, hält keine eigenen Daten außer dem Cache-Zähler).

**Signale:**

| Signal | Erkennung | Schwelle |
|---|---|---|
| `abort` | Jeder `AbortsRun` aus `TurnRunner::run()` (u. a. `ModelDrifted`, `JevNotConfigured`, `JevRejected`) | sofort (1) |
| `auth` (401, 402, 403) | `FatalSignal` liest `job_logs.error` des letzten Turns: `HTTP 401/402/403` | sofort |
| `quota` | dieselbe Prüfung auf `insufficient_quota` im Fehlertext (429 gilt sonst als transient, daher bisher ein leerer Lauf nach etwa 2 h Backoff) | sofort |
| `streak` | Globaler Zähler `study:breaker:streak` im Cache. `turnFailed()` erhöht ihn (`failed`, `interrupted`, `jev_outage`, transiente Exception, Bug), `turnSpoken()` setzt ihn auf 0 | `streak_limit = 8` |

**Hinweis zur Erkennung:** `FatalSignal` ist nur eine Beschleunigung. Greift ein Muster nicht, löst spätestens die Streak aus (Rückfallnetz, Entscheidung 6). Die Muster stehen in `config('study.breaker.fatal_patterns')` (Regex, Signalname). Der Core liefert den Status in `job_logs.error` ab Teilprojekt A (Abschnitt 2 dort: `PromptTaskFailed` trägt Klasse und HTTP-Status). Fehlt der Status in der Nachricht, wird er in A ergänzt, nicht hier geraten.

**Wirkung** bei Auslösung (`trip(signal, reason)`):

1. `PauseFlag::pause($reason, $signal, PauseSource::Breaker)`.
2. Nur wenn die Pause neu gesetzt wurde: `BreakerTripped::dispatch($signal, $reason, $projectId, now())` und `Log::alert(...)`. Jede der sechs gleichzeitigen Auslösungen meldet sich damit nur einmal.
3. Der auslösende Job reiht den Lauf verzögert neu ein und gibt ihn nicht auf (Entscheidung 7).

**Zurücksetzen:** nur durch `study:resume` (oder das Dashboard). Die Streak wird dabei auf 0 gesetzt. Es gibt keine Selbstheilung nach Zeit: Eine Pause, die von selbst endet, wäre unbeaufsichtigt wieder dieselbe leere Welle. Der Streak-Zähler hat 6 h TTL, damit ein Rest vom Vorabend keine Auslösung nachholt.

## 4. systemd-Unit

`app/Study/systemd/communicat-study@.service`:

```ini
# Sechs Worker: systemctl enable --now communicat-study@{1..6}
ExecStart=php artisan queue:work study --queue=study --sleep=5 --max-jobs=200 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=400
KillSignal=SIGTERM
MemoryHigh=450M
MemoryMax=550M
```

- **Worker-Zahl 6** statt 16. Die Worker warten fast nur auf APIs, 2 vCPU genügen. Der Engpass ist der Speicher: Ein Worker (etwa 120 MB) plus bis zu vier `ParallelPrompts`-Kindprozesse (je 60 bis 100 MB). Das sind im Spitzenfall etwa 440 MB je Einheit, sechs Einheiten ergeben 2,6 GB bei 4 GB Gesamtspeicher mit MySQL und System. `MemoryMax` gilt für die ganze Control-Group, die Kinder zählen mit. `MemoryHigh` bremst vor dem OOM-Kill. F stellt 2 GB Swap bereit. Der Probelauf misst die tatsächliche Spitze (Risiko 1).
- **`TimeoutStopSec = 400`** liegt über `turn_timeout` (360 s). `queue:work` beendet bei SIGTERM den laufenden Job und holt keinen neuen. Ein Neustart oder Stopp kostet damit keinen Turn. Ein Test liest die Unit-Datei und prüft diese Beziehung (siehe Tests).
- **`--max-jobs=200 --max-time=3600`** statt `--max-time=21600`: Ein Job ist ein Turn, ein Worker bearbeitet etwa 200 Turns je Stunde. Er startet so regelmäßig neu und begrenzt Speicherlecks. Restart ist billig, weil nichts mehr zwischen Turns hängt. Der Standardwert `--memory=128` des Workers bleibt (er misst nur den Elternprozess).
- **`retry_after`** wird in `app/Study/config.php` aus demselben Wert berechnet: `turn_timeout + 120` = **480 s** (bisher 3900 für 3600 s). Er muss über dem Job-Timeout liegen, sonst nimmt ein zweiter Worker einen lebenden Turn auf. Der Cache-Lock hält `turn_timeout + 60` = 420 s. Die Reihenfolge ist also Timeout 360 < Lock 420 < `retry_after` 480. Die Unit steht davon getrennt (`TimeoutStopSec = 400` > 360).
- **Job-Timeout wirkt nur mit `pcntl`.** Der Worker nimmt den `$timeout` des Jobs, nicht sein eigenes `--timeout` (Standard 60 s), solange der Job einen setzt. Ohne die Erweiterung `pcntl` gibt es keinen Timeout. Der Preflight (F) prüft das.

## 5. Minimaler Zustand: braucht C eine Tabelle?

Nein. Jede Information hat bereits eine Quelle:

| Information | Quelle |
|---|---|
| Fortschritt, Fehlerketten, Backoff-Stufe eines Laufs | `job_logs` (`RunHealth`) |
| Aufgegebener Lauf | Projekt-Tag `superseded` (dauerhaft) plus Zeile in `failed_jobs` (für das Dashboard) |
| Studie pausiert, Grund, seit wann, von wem | `storage/app/study/pause.json` |
| Streak über alle Läufe | Cache (ein Schlüssel) |
| Auslösung des Breakers | Ereignis `BreakerTripped` plus `Log::alert` |

**Was fehlt:** eine Historie der Auslösungen nach dem Resume. Sie braucht C nicht. Teilprojekt D entscheidet, ob es für Discord-Deduplizierung und Lagebericht eine `study_events`-Tabelle anlegt. Es hört dazu auf `BreakerTripped`. Die Notizen nennen `study_events` unter WP 4 (Überwachung), nicht unter WP 3, und diese Spec folgt dem.

## 6. Klassen, Pfade, Konfiguration

Neu unter `app/Study`:

| Datei | Zweck |
|---|---|
| `Control/PauseFlag.php` | `active`, `state`, `pause`, `resume` (Datei, atomar) |
| `Control/PauseState.php` | unveränderlicher Wert: `reason`, `signal`, `source`, `since` |
| `Control/PauseSource.php` | Enum `manual`, `breaker` |
| `Control/CircuitBreaker.php` | `turnSpoken`, `turnFailed(?JobLog)`, `aborted(AbortsRun)`, `unexpected(Throwable)`, privates `trip` |
| `Control/FatalSignal.php` | `matches(string $error): ?string` über `config('study.breaker.fatal_patterns')` |
| `Control/RunHealth.php` | `failedInARow`, `stalledInARow` aus `job_logs` |
| `Control/GiveUp.php` | `mark(Project)`: legt Tag-Kategorie und Wert `superseded = true` bei erster Nutzung an, `setTag` |
| `Events/BreakerTripped.php` | Wertklasse: `signal`, `reason`, `?projectId`, `CarbonImmutable at`. Sonst nichts, D hört darauf |
| `Console/PauseStudy.php`, `Console/ResumeStudy.php` | `study:pause {reason?}`, `study:resume` |

Zu ändern:

| Datei | Änderung |
|---|---|
| `Jobs/RunStudyProject.php` | neu nach Abschnitt 1 (ein Turn, Lock, Re-Dispatch, `ShouldBeUniqueUntilProcessing`, `$tries = 0`, `$timeout` aus Config) |
| `StudyServiceProvider.php` | Singletons, die zwei Befehle, `Queue::looping`-Hook |
| `Console/QueueStudyRuns.php` | überspringt Projekte mit Tag `superseded` (eigene Zeile in der Tabelle). Meldet „Study is paused" und reiht trotzdem ein |
| `config.php` | `tags.superseded`, Block `run`, Block `breaker`, `queue.retry_after` berechnet |
| `systemd/communicat-study@.service` | Abschnitt 4 |
| `README.md` | neue Befehle, Pausen-Datei, Worker-Zahl |

Ausschnitt `config.php`:

```php
'run' => [
    'turn_timeout' => 360,
    'max_failed_in_a_row' => 10,
    'max_stalled_in_a_row' => 12,
    'pause_poll_seconds' => 60,
],
'breaker' => [
    'streak_limit' => 8,
    'fatal_patterns' => ['auth' => '/HTTP (401|402|403)\b/', 'quota' => '/insufficient_quota/'],
],
'pause_file' => storage_path('app/study/pause.json'),
// 'queue.retry_after' => turn_timeout + 120
```

Core-Grenze: C nennt im Core nichts. `StudyModuleBoundaryTest` bleibt grün, weil der Core weder `PauseFlag` noch `BreakerTripped` kennt.

## 7. Tests (`tests/Feature/Study`)

**Job `RunStudyProjectTest`** (mit `FakeAgents`, Queue `sync`/`fake` je Fall):

- Ein Aufruf spricht genau einen Turn und reiht einen Nachfolger mit Delay 0 ein.
- `turn_budget` beendet ohne Nachfolger. Ein weiterer Job für ein erledigtes Projekt tut nichts.
- Ein zweiter gleichzeitiger Job (gehaltener Lock) tut nichts und reiht nichts ein.
- Aktive Pause: kein Turn, `release`.
- `superseded`-Projekt: kein Turn, kein Nachfolger.
- `interrupted`: Nachfolger mit Backoff aus `stalledInARow` (60, 120, … 900), Zeile im Log.
- `failed` bis `max_failed_in_a_row`: danach Tag `superseded` und `fail()`. Unterhalb davon Delay 0.
- Ein Turn, der sprach und in `Summarize` scheiterte, unterbricht die Fehlerkette (Zählregel).
- Pausiert oder ausgelöst im selben Turn: **kein** Aufgeben trotz Schwelle.
- `AbortsRun` (Fake): Breaker löst aus, Lauf bleibt offen, Nachfolger mit Pausen-Delay.
- Unerwartete Exception: Streak steigt, Exception wird weitergeworfen.
- `closeAbandonedTurns`: ein `running`-Log wird `interrupted` und zählt in `stalledInARow`.

**Breaker und Pause:**

- `CircuitBreakerTest`: Streak bis 8 löst aus, ein gesprochener Turn setzt sie auf 0. `FatalSignal` löst bei „HTTP 401" und „insufficient_quota" sofort aus. Sechs gleichzeitige Auslösungen erzeugen genau ein `BreakerTripped`.
- `PauseFlagTest`: `pause` ist atomar (zweiter Aufruf `false`, erster Grund bleibt), `resume` löscht Datei und Streak, eine unlesbare Datei gilt als pausiert, `cache:clear` hebt die Pause nicht auf.
- `StudyPauseCommandTest`: `study:pause "Grund"` und `study:resume`, Ausgabe des alten Zustands.
- `LoopingHookTest`: Mit aktiver Pause holt `queue:work --once` auf `study` keinen Job, auf einer anderen Verbindung schon.

**Übrige:**

- `RunHealthTest`: Ketten aus synthetischen `job_logs`-Zeilen, auch die `jev_outage`-Zeile (Status success, keine Wörter).
- `QueueStudyRunsTest`: Projekte mit `superseded` werden übersprungen, `study:queue` zweimal ergibt nur einen Job je Lauf (Unique-Sperre).
- `StudyOpsConfigTest`: `turn_timeout < Lock-TTL < retry_after` und in der Unit-Datei `TimeoutStopSec > turn_timeout` (Datei per Regex lesen).
- `StudyModuleBoundaryTest` bleibt unverändert grün.

## 8. Offene Risiken

1. **Speicher auf 4 GB** (Entscheidung zur Worker-Zahl). Die Rechnung oben ist geschätzt. Der Chaos-Probelauf mit sechs Workern und Score-Läufen (vier parallele Kinder) muss `MemoryMax` und die Zahl 6 belegen. Zeigt `systemctl status` OOM-Kills, sinkt die Worker-Zahl auf 4.
2. **`turn_timeout` 360 s ist geraten.** Ein legitimer Turn, der länger dauert, wird jedes Mal abgebrochen und füllt `stalledInARow`, bis der Lauf aufgegeben wird. Der Probelauf misst P99 je Pipeline (Jev-Fallbacks ergeben Spitzen von bis zu 180 s allein bei Jev). Der Wert wird danach festgelegt.
3. **Fehlertext als Vertrag.** `FatalSignal` liest `job_logs.error` auf „HTTP 401" und `insufficient_quota`. Ändert sich der Text (SDK-Update, Teilprojekt A), greift die Beschleunigung nicht mehr. Das Rückfallnetz (Streak 8) fängt es ab, aber später und mit mehr bezahlten Fehlturns. A muss den HTTP-Status in die Nachricht von `PromptTaskFailed` schreiben, das ist in A zu bestätigen.
4. **Falscher Alarm durch einen einzelnen `AbortsRun`.** Ein 422 von Jev bei einem einzelnen Lauf pausiert die ganze Studie. Das ist beabsichtigt (Entscheidung 5), kostet aber bei unbeaufsichtigtem Betrieb Stunden, bis jemand resumed. D muss die Pause sofort melden.
5. **Doppelte Kette bei Tod zwischen Re-Dispatch und Löschen.** Stirbt der Worker, nachdem er den Nachfolger eingereiht hat und bevor er den eigenen Job löscht, kommt der alte Job nach `retry_after` zurück. Zwei Ketten laufen dann abwechselnd (der Cache-Lock verhindert Parallelität). Sie enden gemeinsam am Budget. Folge ist nur doppelte Worker-Belegung, keine Datenverfälschung. Nicht behoben (YAGNI).
6. **`uniqueFor = 3600` nach `queue:clear`.** Nach einem manuellen Leeren der Queue blockiert die Unique-Sperre ein erneutes `study:queue` für bis zu einer Stunde. Abhilfe: `cache:clear` (die Pausen-Datei bleibt dabei erhalten) oder warten. Muss in der README stehen.
7. **Pausen-Datei nur auf einem Host.** Läuft die Web-Anwendung und laufen die Worker nicht auf demselben Host (oder unter anderem Benutzer, der die Datei nicht schreiben darf), wirkt das Dashboard nicht. Für den einen VPS richtig, bei Verteilung wäre ein Cache-Store mit Persistenz nötig. Der Preflight prüft Schreibrechte auf `storage/app/study`.
8. **`Queue::looping` und `--sleep`.** Der pausierte Worker schläft `--sleep` (5 s) je Runde. Das ist Verhalten von Laravel 12, der Test in Abschnitt 7 sichert es gegen Änderungen bei einem Update ab.
9. **Zählregel bei `jev_outage`.** Die halbierende Zeile (Status success, keine Wörter) zählt als `stalled`, nicht als `failed`. Wer sie anders will, ändert nur `RunHealth`.
10. **Tag-API unbestätigt.** `GiveUp` setzt voraus, dass `TagCategory`/`TagValue` ohne Admin-Kontext anlegbar sind und dass `setTag` für Projekt-Tags die Kategorie `superseded` akzeptiert. Der Plan liest `HasTags` und die Tag-Modelle und passt `GiveUp` an. Der Schlüssel `superseded` darf mit B (Tag-Namen) nicht kollidieren.

## Umsetzungsreihenfolge

1. `PauseFlag`, `PauseState`, `PauseSource`, Befehle `study:pause` und `study:resume`, Looping-Hook.
2. `RunHealth`, `FatalSignal`, `CircuitBreaker`, `BreakerTripped`.
3. `GiveUp` und der Tag `superseded`, `study:queue` überspringt ihn.
4. `RunStudyProject` neu (ein Turn, Lock, Unique).
5. Konfiguration und Unit (Timeout, `retry_after`, Worker-Zahl, Speichergrenzen).
6. Tests, dann README und CLAUDE.md (Abschnitt „Study batches" ersetzt den Satz „The job loops `TurnRunner` to the budget").
