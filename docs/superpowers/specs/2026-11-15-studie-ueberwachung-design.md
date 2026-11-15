# Spec: Teilprojekt D „Überwachung“ der Studien-Engine

Datei: `docs/superpowers/specs/2026-11-15-studie-ueberwachung-design.md`

Stand: 15.11.2026. Grundlage sind die Entscheidungen 16 und 17 und der Maßnahmenkatalog. Die Spec setzt Teilprojekt C (Steuerung) in der Form voraus, die unter „Annahmen“ steht.

---

> **Abgleich mit C (15.11., Orchestrator):** Die Spec C (`2026-11-15-studie-steuerung-design.md`) ist verbindlich.
> Wo D unten etwas annimmt, gilt: Ereignis ist `App\Study\Events\BreakerTripped` (`signal`, `reason`, `?projectId`,
> `at`) statt `StudyHalted`; das Pausen-Flag ist `App\Study\Control\PauseFlag` (Datei `storage/app/study/pause.json`,
> `state(): ?PauseState` mit `reason`, `signal`, `source` = `PauseSource::Manual|Breaker`, `since`); die Knöpfe rufen
> `PauseFlag::pause($reason, 'manual', PauseSource::Manual)` bzw. `resume()`. Modell-Drift kommt als `AbortsRun`
> (`ModelDrifted`, Teilprojekt A) über den Breaker mit `signal = abort`. C legt **keine** Tabelle an: `study_events`
> gehört allein D. C registriert einen `Queue::looping`-Hook, der pausierte Worker schlafen lässt; der Heartbeat-
> Listener von D auf `Looping` feuert trotzdem und bleibt gültig. `PauseView` entfällt, D liest `PauseFlag` direkt.

## 0. Ziel und Abgrenzung

Eine Welle läuft 1–2 Tage unbeaufsichtigt auf dem VPS. D sorgt dafür, dass der Forscher es merkt, wenn sie stehen bleibt, gebremst wird oder fertig ist. D kann den Stand auch auf Zuruf abfragen.

- **Der Bot meldet und beantwortet Abfragen. Er steuert nichts.** Pause und Resume gibt es nur im Dashboard und per CLI (`study:pause`, `study:resume`).
- **Blindheitsregel:** Nichts in D liest oder zeigt einen Anteil, Gini, eine Effektgröße oder einen Verlauf davon. Das gilt für Dashboard, `/status`, Lagebericht, Mail und Logs. Ein Architekturtest prüft es (Abschnitt 6).
- **YAGNI:** Es gibt keine neue Composer-Abhängigkeit, keine Discord-Bibliothek und kein Gateway. Der Bot hat keine WebSocket-Verbindung und ist für Discord nie „online“. Er sendet über REST und antwortet auf HTTP-Interactions.
- **Core nennt `app/Study` nie.** Alles liegt unter `app/Study`. Core bekommt keinen neuen Haken (`StudyModuleBoundaryTest` bleibt grün).

### Annahmen über Teilprojekt C

C liefert diese Teile. Weichen die Namen ab, ändert sich nur der Adapter `Watch/PauseView` (Abschnitt 4).

| Annahme | Verwendung in D |
|---|---|
| `App\Study\Events\StudyHalted` mit `string $reason` und `CarbonImmutable $at` | Listener meldet sofort |
| Ein Pausen-Flag mit Lesezugriff: `reason`, `since`, `manual\|breaker` | Dashboard-Kopf, Lagebericht, Alarm `paused` |
| `study:pause {reason?}` und `study:resume` | Dashboard-Knöpfe rufen dieselbe Klasse auf wie die Befehle |
| Modell-Drift (M9) löst `StudyHalted('model_drift')` aus | Drift-Alarm ohne eigenen Code in D |

Zu Drift: `GuardAgainstModelDrift` liegt im Core und loggt nur. D liest es **nicht** aus dem Log. Bleibt der Drift-Halt in A/C aus, gibt es keinen Drift-Alarm. Das steht in den Risiken (Abschnitt 7).

---

## 1. Ein Lesemodell für alle Anzeigen

### Befund

`Progress/StudyProgress::read()` ist schon das Lesemodell. Es liefert Zähler, Tempo, ETA, `lastTurnAt`, `stale`, Störungen, Queue und gescheiterte Jobs. Die Stillstandsregel steckt in der Zeile `'stale' => $remaining > 0 && …`. Dashboard, Bot und Watcher sollen diese Regel **nicht** nochmal bauen.

### Entscheidung

`StudyProgress` bleibt unverändert und behält die Grids. Darüber kommt eine dünne Hülle. Sie fügt das hinzu, was C und D beisteuern, und ist die einzige Quelle für alle drei Verbraucher.

**`app/Study/Progress/StudySnapshot.php`** ist ein `final readonly` Value Object:

```php
final readonly class StudySnapshot
{
    public function __construct(
        public array $progress,            // StudyProgress::read() unverändert (inkl. grids, missing)
        public ?PauseState $pause,         // aus Watch/PauseView, null = läuft
        public ?CarbonImmutable $workerSeenAt, // Heartbeat, Abschnitt 4
        public Collection $recentEvents,   // letzte 10 study_events
    ) {}

    public static function read(?int $wave = null): self;   // baut aus den App-Singletons

    public function hasCells(): bool;     // progress['missing'] === []
    public function remaining(): int;     // Summe offener Turns, aus progress
    public function standstill(): bool;   // progress['stale'], keine zweite Regel
    public function finished(): bool;     // totals.total > 0 && done === total
}
```

`read()` ruft `app(StudyProgress::class)->read($wave)`. `remaining()` rechnet `hoursLeft × turnsPerHour` nicht zurück. Es braucht eine Zeile in `StudyProgress`:

- Die Zeile `$remaining = …` wird zusätzlich als `'remaining' => $remaining` in das Rückgabe-Array übernommen. Das ist die einzige Änderung an `StudyProgress`.
- Der Test `StudyProgressTest` bleibt bis auf den neuen Schlüssel unverändert.

**`app/Study/Progress/StatusText.php`** ist ein Formatierer ohne Zustand:

- `summary(StudySnapshot): string` ist eine kompakte Markdown-Nachricht (höchstens 1 800 Zeichen).
- Er dient `/status`, dem Lagebericht und dem Mailtext.
- `ProgressPage` braucht ihn nicht. Sie rendert den Snapshot in Blade.

Inhalt von `summary()` (alles aus `progress`, nichts Neues):

1. Kopfzeile: Pausenstatus (`PAUSIERT seit 14:03 · circuit_breaker` oder `läuft`) und Wellenfilter.
2. `done/total`, plus gestartet, offen, aufgegeben.
3. Tempo (Turns pro Stunde), Rest-Stunden, letzter fertiger Turn („vor 3 min“), Worker-Signal („vor 12 s“), Queue-Länge.
4. Eine Zeile je Pipeline mit `done/total`. Es gibt höchstens fünf Pipelines.
5. Die drei häufigsten Störungen der letzten Stunde und die letzten drei aufgegebenen Läufe (Projekt-ID und Fehlerklasse, nie der Text).

Das Mapping von Zustand zu Text liegt in `StatusText` und in den Übersetzungen `study::watch.*`. Die Dashboard-Karten verwenden dieselben Lang-Schlüssel wie bisher.

---

## 2. `study:watch` (Scheduler, jede Minute)

### Registrierung

Im `StudyServiceProvider::boot()`, nur bei `study.enabled` und nur in der Konsole:

```php
$this->callAfterResolving(Schedule::class, fn (Schedule $s) =>
    $s->command('study:watch')->everyMinute()->withoutOverlapping(5));
$this->commands([QueueStudyRuns::class, WatchStudy::class, RegisterDiscordCommands::class, ...]);
```

`routes/console.php` im Core bleibt unberührt. `composer dev` startet `schedule:work` bereits. In Produktion gilt dasselbe wie bei `prompts:prune` (Scheduler-Unit aus `service.example`).

### Aufbau

- **`Console/WatchStudy.php`** ist nur der Aufruf: `app(StudyWatcher::class)->run()` und danach der Ping (Abschnitt 5).
- **`Watch/StudyWatcher.php`** liest einmal `StudySnapshot::read()` (alle Wellen) und führt die Prüfungen aus.

Prüfungen je Minute, jede mit einer Bedingung als `bool` und einem Text:

| Art | Bedingung | Stufe |
|---|---|---|
| `standstill` | `snapshot->standstill()` | kritisch |
| `paused` | `snapshot->pause !== null` | kritisch bei Breaker, Info bei manuell |
| `wave_finished` | `snapshot->finished()` | Info, einmalig je Zustand |
| Lagebericht | `report_every_hours` seit dem letzten Bericht und (`remaining > 0` oder pausiert) | Info |

Der Drift-Alarm ist keine eigene Prüfung. Er kommt über `StudyHalted('model_drift')` und `paused` (siehe unten).

Der Text des Standstill-Alarms nennt die Zeit seit dem letzten fertigen Turn **und** das Worker-Signal. Ein frisches Signal mit altem Turn heißt: Jobs laufen und scheitern. Ein altes Signal heißt: Worker sind tot.

### Dedup und Entwarnung: `Watch/AlertBook.php`

Der Zustand liegt im Cache unter `study.alert.{kind}`. Der Cache-Treiber muss persistent sein (`database`, `file` oder `redis`, nicht `array`).

```php
sync(string $kind, bool $active, Closure $alert): void
```

- Aktiv und kein Eintrag im Cache: **senden**, Eintrag `{since, lastSent}` setzen.
- Aktiv und Eintrag älter als `repeat_alert_minutes`: **Erinnerung** senden („weiterhin: …, seit 3 h“), `lastSent` setzen.
- Aktiv und Eintrag frisch: nichts senden.
- Nicht aktiv und Eintrag vorhanden: **Entwarnung** senden („Stillstand behoben nach 42 min“), Eintrag löschen.
- `$alert` ist ein Closure, damit der Text nur gebaut wird, wenn er gesendet wird.

Besonderheiten:

- `wave_finished` bekommt einen Eintrag ohne Wiederholung. `repeat` ist dort deaktiviert, und die Entwarnung entfällt. Der Eintrag verschwindet, sobald wieder Läufe offen sind (neue Welle).
- Der Lagebericht läuft nicht über AlertBook. Er nutzt `Cache::add`/`put('study.report.last')` und eine Zeitdifferenz. Geht der Cache verloren, kommt der nächste Bericht sofort. Das ist unkritisch.

### Der Weg einer Meldung: `Watch/Notifier.php`

`Notifier::send(Alert $alert, bool $mail)`:

1. Schreibt eine Zeile in `study_events` (Abschnitt 4), immer.
2. Sendet an Discord über `DiscordClient::post()`, falls Token und Kanal gesetzt sind.
3. Sendet eine Mail über `Mail::raw`, falls `study.alerts.mail` gesetzt ist und `$mail` gilt. **Alarme und Entwarnungen gehen per Mail, Lageberichte nicht.**
4. Jeder Kanal hat sein eigenes `try/catch`. Ein ausgefallener Kanal wird geloggt (`Log::warning`) und stoppt weder den anderen Kanal noch die Prüfungen. Fallen **beide** Kanäle aus, steht der Alarm trotzdem in `study_events` und im Dashboard.

`Watch/Alert.php` ist ein kleines readonly-Objekt: `kind`, `level` (`info|warn|crit|ok`), `title`, `body`.

### Sofortmeldung bei `StudyHalted`

`Watch/AnnounceHalt.php` ist ein Listener, den der Provider registriert (`Event::listen(StudyHalted::class, …)`). Er ruft denselben `AlertBook::sync('paused', true, …)` auf wie der Watcher. Wer zuerst kommt, sendet, der andere entdeckt den Eintrag. Das gibt Sofortmeldung und Nachholung innerhalb einer Minute, falls der Listener scheitert oder Discord gerade nicht antwortet.

### Pausen-Adapter: `Watch/PauseView.php`

Er liest C's Pausen-Flag und gibt `?PauseState` (`reason`, `since`, `byBreaker`) zurück. Er ist die einzige Stelle in D, die C's Klassen kennt.

---

## 3. Discord

### 3.1 Client (`Discord/DiscordClient.php`)

Der Client benutzt Laravels `Http`-Fassade. Base-URL ist `https://discord.com/api/v10`.

```php
post(string $content): void             // POST /channels/{channel}/messages
registerCommands(): void                // PUT /applications/{app}/commands oder /guilds/{guild}/commands
```

Gemeinsame Konfiguration:

- Header `Authorization: Bot {token}` und ein `User-Agent` im Format `DiscordBot (https://communicat.example, 1.0)` (Discord verlangt dieses Format).
- `timeout(5)` und `connectTimeout(3)`.
- Nachrichten länger als 2 000 Zeichen werden vor dem Senden hart gekürzt (`Str::limit`), nie geteilt.
- Zeichenfolgen in `@everyone` und `@here` werden entschärft: `allowed_mentions: {parse: []}` im Body.

**Rate-Limit-Behandlung** (Statuscode 429): Antwort-JSON `retry_after` (Sekunden, Fließkomma) lesen, `Sleep::for(min(retry_after, 10))` und genau einen erneuten Versuch senden. Scheitert der zweite Versuch oder liegt `retry_after` über 10 s, wirft der Client `DiscordUnavailable`. Der Notifier fängt das (Abschnitt 2). Es gibt keine Warteschlange und keine Wiederholung über Minuten hinaus, denn die nächste Minute prüft ohnehin neu.

Ein 401 oder 403 (falsches Token, Bot nicht im Kanal) ist ein eigener Fall: Der Client wirft `DiscordRejected` mit Statuscode. Das wird als `warning` geloggt und im Dashboard als „Discord abgelehnt (401)“ in der Ereignisliste sichtbar, weil `Notifier` es als Event `discord_rejected` einträgt (einmal je Stunde, über `AlertBook`).

Der Client verwendet **kein** Gateway und keine Bibliothek. `ext-sodium` ist installiert (geprüft: `php -m` zeigt `sodium`). Eine Zusatzabhängigkeit entfällt.

### 3.2 Interactions-Endpunkt

Der Endpunkt ist nur aktiv, wenn `DISCORD_PUBLIC_KEY` gesetzt ist. Der Provider registriert ihn **außerhalb** der Admin-Gruppe:

```php
if (config('study.enabled') && config('study.discord.public_key')) {
    Route::post('study/discord/interactions', InteractionController::class)
        ->middleware('throttle:60,1')
        ->name('study.discord.interactions');
}
```

Eigenschaften:

- **Kein `web`-Middleware-Stack.** Damit fallen Session, Cookies und CSRF-Prüfung weg. Eine Ausnahmeliste für CSRF (`withoutMiddleware`) ist nicht nötig, und `bootstrap/app.php` bleibt unangetastet.
- **Signaturprüfung vor allem anderen:** `Discord/SignatureVerifier::valid(string $rawBody, ?string $signature, ?string $timestamp): bool`.
  - Header `X-Signature-Ed25519` (hex) und `X-Signature-Timestamp`.
  - Prüft: beide Header vorhanden, Signatur hat 128 Hex-Zeichen, Key hat 64 Hex-Zeichen.
  - Dann `sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp.$rawBody, hex2bin($publicKey))`.
  - Der **Rohtext** kommt aus `$request->getContent()`. Wird der Body vorher als JSON gelesen und neu serialisiert, bricht die Signatur.
  - Zusätzlich: `abs(time() - (int) $timestamp) <= 300`, sonst ungültig. Das ist ein einfacher Wiederholungsschutz. Discord schreibt ihn nicht vor, schadet aber nicht.
- **Ungültig:** HTTP 401 ohne Inhalt. Discord testet das beim Speichern der Endpunkt-URL im Developer Portal mit absichtlich falschen Signaturen und verlangt 401.
- **`type: 1` (PING):** Antwort `{"type": 1}`.
- **`type: 2` (APPLICATION_COMMAND), `data.name = status`:**
  - Der Kanal der Interaktion (`channel_id`) muss `DISCORD_CHANNEL_ID` entsprechen. Sonst kommt eine **ephemere** Ablehnung (`flags: 64`, „Nur im Studienkanal“). So kann niemand, der den Bot auf einen anderen Server einlädt, Studienzahlen abfragen.
  - Antwort `{"type": 4, "data": {"content": StatusText::summary(StudySnapshot::read())}}`.
  - `allowed_mentions: {parse: []}` auch hier.
- **Alles andere:** 400.

Das Zeitlimit für die Antwort ist 3 Sekunden. `StudySnapshot::read()` macht rund ein Dutzend Abfragen, nicht eine je Lauf. Bei 3 072 Läufen ist das bei SQLite/MySQL gut machbar. Wird es knapp, kann `read()` 15 Sekunden gecacht werden (Risiko 3).

### 3.3 Befehlsregistrierung

**`Console/RegisterDiscordCommands.php`**, Signatur `study:discord-register`:

- Meldet den Slash-Befehl `status` („Stand der Studie“, ohne Optionen) an.
- `PUT` überschreibt die gesamte Liste, der Befehl ist daher idempotent.
- Gilt `DISCORD_GUILD_ID`, wird er nur auf dem Server registriert (wirkt sofort, ideal für die Studie). Ohne Guild ist er global (Verzögerung bis zu einer Stunde).
- Braucht `DISCORD_APPLICATION_ID` und `DISCORD_BOT_TOKEN`. Es gibt klare Fehlermeldungen („DISCORD_APPLICATION_ID fehlt“).
- Einmaliger Aufruf bei der Einrichtung.
- Der Befehl `study:discord-register` gibt zusätzlich die URL aus, die man im Developer Portal unter „Interactions Endpoint URL“ einträgt (`route('study.discord.interactions')`).

### 3.4 Einrichtung (steht auch im README)

1. Anwendung im Developer Portal anlegen, Bot hinzufügen, Token kopieren.
2. Bot auf den Server einladen (Scope `bot` und `applications.commands`, Recht „Nachrichten senden“ im Kanal).
3. `.env` ausfüllen (Abschnitt 6.1).
4. `php artisan study:discord-register`.
5. Interactions Endpoint URL eintragen. Discord schickt einen PING und prüft, ob 401 bei falscher Signatur kommt.
6. `/status` im Kanal testen.

---

## 4. Dashboard-Erweiterungen (`/study`)

`ProgressPage` bleibt eine Seite. Sie ruft `StudySnapshot::read($this->wave)` statt `StudyProgress::read()` und gibt `$snapshot->progress` an die bestehende Ansicht weiter, plus die neuen Teile.

### 4.1 Pausenstatus

- Oberhalb der Kennzahlen eine `flux:callout` (rot bei Breaker, gelb bei manueller Pause): „Studie pausiert seit 14:03 · Grund: `circuit_breaker`“.
- Läuft die Studie, steht im Kopf eine kleine „läuft“-Marke.
- Der Grund steht so da, wie ihn C liefert. D übersetzt nichts.

### 4.2 Knöpfe Pause und Resume mit Bestätigung

- `ProgressPage` nimmt `use NeedsConfirmation`.
- Ein Knopf je Zustand: „Pausieren“ (sichtbar, wenn nicht pausiert) und „Fortsetzen“ (sichtbar, wenn pausiert).
- `wire:click="needsConfirmation('pauseStudy')"` und `wire:click="needsConfirmation('resumeStudy')"`.
- `$confirmLabel` wird beim Klick gesetzt („Studie pausieren“ / „Studie fortsetzen“), `$confirmMessage` nennt Laufzahl und Rest-Turns.
- `pauseStudy()` und `resumeStudy()` sind **öffentliche Methoden der Komponente** und rufen `Gate::authorize('admin')` selbst auf (laut CLAUDE.md umgeht `executeConfirmed()` die Livewire-Attribute). Dann rufen sie die Klasse von C (dieselbe wie `study:pause`/`study:resume`) mit `reason: 'manual'`.
- Eine Pause ist **global**, nicht je Welle. Der Wellenfilter der Seite beeinflusst sie nicht. Der Bestätigungstext sagt „alle Wellen“.
- `x-confirm-modal` liegt bereits im Sidebar-Layout. Die Seite braucht kein eigenes Modal.
- Nach der Aktion: Event in `study_events` („pausiert von Admin“ / „fortgesetzt von Admin“), und die Seite zeigt den neuen Zustand beim nächsten Rendern.

### 4.3 Worker-Heartbeat

**Wie:** Nicht jeder Job schreibt einen Zeitstempel. Besser eignet sich das Queue-Ereignis `Illuminate\Queue\Events\Looping`. Es feuert in **jedem** Durchlauf der Worker-Schleife, auch im Leerlauf und während eines Backoffs, in dem kein Job startet. Ein job-basierter Heartbeat wäre bei leerer Queue oder 15-Minuten-Backoff still und sähe aus wie ein toter Worker.

- **`Watch/WorkerHeartbeat.php`** hört auf `Looping`. Er reagiert nur auf `$event->queue === 'study'`.
- Er schreibt `Cache::put('study.worker.seen', now()->timestamp, 3600)`, höchstens alle 15 Sekunden je Prozess (ein statischer Zeitstempel im Prozess, kein Cache-Lesen). Bei sechs Workern sind das rund 0,4 Schreibvorgänge pro Sekunde.
- `StudySnapshot::read()` liest `study.worker.seen` als `workerSeenAt`.
- Die Anzeige in der Karte „Letzter Turn“: „Worker-Signal vor 12 s“. Sie wird rot, wenn das Signal älter ist als `stale_after_minutes` **und** Läufe offen sind.

Es gibt **ein** gemeinsames Signal (letzter Schreiber gewinnt), keine Liste je Prozess. Die Anzahl lebender Worker zeigt es nicht. Das genügt für „steht still“ gegen „läuft“. Eine Liste je Worker wäre ein Cache-Schlüsselverzeichnis und eigener Aufwand.

Der Provider registriert den Listener immer (auch bei `STUDY_MODULE=false`), weil Worker unabhängig vom Schalter weiterlaufen können. Er ist wirkungslos, solange niemand die Queue `study` abarbeitet.

### 4.4 Liste der letzten Alarme

- Tabelle **`study_events`**, Migration unter `app/Study/database/migrations` (der Provider ruft `loadMigrationsFrom`; ohne Modul entfällt die Migration, sie entfernt man beim Löschen des Ordners mit `migrate:rollback --path`):

| Spalte | Typ |
|---|---|
| `id` | bigint |
| `kind` | string (`standstill`, `paused`, `wave_finished`, `report`, `discord_rejected`, `manual`) |
| `level` | string (`info`, `warn`, `crit`, `ok`) |
| `message` | text |
| `created_at` | timestamp, Index |

- `Models/StudyEvent.php` (Modell im Modul, nicht unter `App\Models`).
- Der Notifier schreibt jede gesendete Meldung hinein, außer Lageberichten. Die erscheinen nur als `report` mit Zeitstempel und ohne Text (sonst füllt sich die Liste).
- Die Karte „Letzte Meldungen“ zeigt die letzten 10 mit Stufe, Zeit und Text. Das dient auch als Notfall-Anzeige, falls Discord und Mail beide ausgefallen sind.
- **Koordination mit C:** Legt C schon eine Ereignistabelle an (Notizen WP4 nennen `study_events`), gilt eine gemeinsame. Es gibt dann nur **eine** Migration, und D ergänzt höchstens `kind`-Werte. Klären vor dem Bau.

Die Wellen-Kopfzeile, Kosten und Budget gehören nicht zu D.

---

## 5. Externer Dead-Man's-Switch

`study:watch` pingt am **Ende eines erfolgreichen Laufs** eine healthchecks.io-URL:

```php
Http::timeout(5)->get(config('study.healthcheck_url'));   // nur wenn gesetzt
```

- **Nur bei Erfolg:** Wirft ein Teil von `StudyWatcher::run()` (Datenbank weg, Bug), erreicht der Befehl den Ping nicht, und healthchecks.io schlägt nach der Karenzzeit Alarm. Ausfälle von Discord oder Mail werfen nicht (Abschnitt 2), sie dürfen den Ping nicht verhindern.
- Ein Fehler beim Ping selbst (Timeout) wird nur als `Log::warning` vermerkt und wirft nicht.
- Der Ping zeigt: Scheduler, PHP und Datenbank leben. Er zeigt **nicht**, dass Worker leben. Das ist Aufgabe des Standstill-Alarms.
- Empfohlene Check-Einstellung bei healthchecks.io: Periode 1 Minute, Karenzzeit 10 Minuten. Der Alarm kommt dort über die Wege des Dienstes (z. B. SMS), unabhängig von VPS, Discord und Laravel-Mail.
- Optional, nicht gebaut: `/fail` pingen, wenn ein Standstill aktiv ist. Ohne diese Kopplung bleibt die Trennung klar (Ping heißt „Überwachung lebt“).

---

## 6. Konfiguration, Klassen, Pfade, Tests

### 6.1 Env- und Config-Schlüssel

In `app/Study/config.php` (gemerged als `config('study')`) kommen dazu:

```php
'watch' => [
    'repeat_alert_minutes' => (int) env('STUDY_ALERT_REPEAT_MINUTES', 180),
    'report_every_hours'   => (float) env('STUDY_REPORT_EVERY_HOURS', 6),   // 0 = aus
],
'discord' => [
    'token'          => env('DISCORD_BOT_TOKEN'),
    'channel_id'     => env('DISCORD_CHANNEL_ID'),
    'application_id' => env('DISCORD_APPLICATION_ID'),
    'guild_id'       => env('DISCORD_GUILD_ID'),
    'public_key'     => env('DISCORD_PUBLIC_KEY'),   // gesetzt = Interactions-Endpunkt aktiv
],
'alerts' => ['mail' => env('STUDY_ALERT_MAIL')],
'healthcheck_url' => env('STUDY_HEALTHCHECK_URL'),
```

Der bestehende Schlüssel `stale_after_minutes` gilt unverändert auch für den Alarm.

| Env | Pflicht | Wirkung |
|---|---|---|
| `DISCORD_BOT_TOKEN`, `DISCORD_CHANNEL_ID` | für Discord-Meldungen | beide gesetzt = Meldungen und Lagebericht gehen an Discord |
| `DISCORD_PUBLIC_KEY` | für `/status` | gesetzt = Endpunkt registriert |
| `DISCORD_APPLICATION_ID`, `DISCORD_GUILD_ID` | nur für `study:discord-register` | |
| `STUDY_ALERT_MAIL` | optional | Empfänger der Alarm-Mails, nutzt die `MAIL_*`-Einstellungen der App |
| `STUDY_HEALTHCHECK_URL` | optional | Ping-URL |
| `STUDY_REPORT_EVERY_HOURS` | optional (6) | Takt des Lageberichts |
| `STUDY_ALERT_REPEAT_MINUTES` | optional (180) | Erinnerung bei anhaltendem Alarm |

Alle Schlüssel kommen in `.env.example` und `.env.study.example` (leer) sowie in `phpunit.xml` (leer). Tests setzen sie per `config([...])`. Keiner der Tests erreicht Discord, Mail oder healthchecks.io.

Fehlen Token und Kanal, läuft der Watcher trotzdem: Meldungen landen dann nur in `study_events` und im Dashboard.

### 6.2 Neue Dateien

| Datei | Zweck |
|---|---|
| `app/Study/Progress/StudySnapshot.php` | Lesemodell (Abschnitt 1) |
| `app/Study/Progress/StatusText.php` | Textfassung für `/status`, Bericht, Mail |
| `app/Study/Watch/StudyWatcher.php` | Prüfungen je Minute |
| `app/Study/Watch/AlertBook.php` | Dedup, Erinnerung, Entwarnung im Cache |
| `app/Study/Watch/Alert.php` | Value Object |
| `app/Study/Watch/Notifier.php` | `study_events` + Discord + Mail, Fehler je Kanal gekapselt |
| `app/Study/Watch/AnnounceHalt.php` | Listener auf `StudyHalted` |
| `app/Study/Watch/PauseView.php` | Adapter auf C's Pausen-Flag |
| `app/Study/Watch/WorkerHeartbeat.php` | Listener auf `Looping` |
| `app/Study/Console/WatchStudy.php` | `study:watch` |
| `app/Study/Console/RegisterDiscordCommands.php` | `study:discord-register` |
| `app/Study/Discord/DiscordClient.php` | REST, Rate-Limit |
| `app/Study/Discord/SignatureVerifier.php` | Ed25519-Prüfung |
| `app/Study/Discord/InteractionController.php` | PING, `/status` |
| `app/Study/Discord/DiscordUnavailable.php`, `DiscordRejected.php` | Ausnahmen |
| `app/Study/Models/StudyEvent.php` | Ereigniszeile |
| `app/Study/database/migrations/…_create_study_events_table.php` | Tabelle |
| `tests/Feature/Study/…` | siehe 6.4 |

### 6.3 Geänderte Dateien (alle unter `app/Study`)

| Datei | Änderung |
|---|---|
| `StudyServiceProvider.php` | Schedule, Commands, Listener (`StudyHalted`, `Looping`), Interactions-Route, `loadMigrationsFrom` |
| `config.php` | Abschnitt 6.1 |
| `Progress/StudyProgress.php` | `'remaining'` im Rückgabe-Array |
| `Livewire/ProgressPage.php` | `StudySnapshot`, `NeedsConfirmation`, `pauseStudy()`/`resumeStudy()` |
| `views/progress.blade.php` | Pausen-Callout, Knöpfe, Worker-Signal, Karte „Letzte Meldungen“ |
| `lang/{de,en}/progress.php` und neu `lang/{de,en}/watch.php` | Texte für Dashboard und Meldungen |
| `README.md` | Tabelle um die neuen Teile, Abschnitt Discord-Einrichtung, Hinweis zum Entfernen (Migration zurückrollen) |

Core bleibt unberührt.

### 6.4 Tests (`tests/Feature/Study`)

`Http::fake()` fängt jeden Aufruf ab, `Http::preventStrayRequests()` steht in `setUp`. `Carbon::setTestNow` steuert die Zeit, `Sleep::fake()` die Wartezeit beim Rate-Limit, `Mail::fake()` die Mail.

**Signatur** (`DiscordSignatureTest`): Der Test erzeugt ein Schlüsselpaar mit `sodium_crypto_sign_keypair()` und signiert `timestamp.body` mit `sodium_crypto_sign_detached`. Er setzt den Public Key (hex) in die Config.
- gültig: 200
- Body nach dem Signieren verändert: 401
- falscher Key: 401
- fehlender oder kaputter Header (zu kurz, kein Hex): 401
- Zeitstempel älter als 5 Minuten: 401
- Endpunkt ohne `DISCORD_PUBLIC_KEY`: 404, Route nicht registriert

**Interaktion** (`DiscordInteractionTest`):
- PING liefert `{"type":1}`
- `/status` im richtigen Kanal liefert `type 4` mit Zahlen aus dem Snapshot
- `/status` im falschen Kanal liefert die ephemere Ablehnung und **keine** Zahlen
- unbekannter Befehl: 400
- Keine Anfrage erfordert CSRF oder Anmeldung (Test ohne Session)

**Client** (`DiscordClientTest`):
- `post()` sendet `Authorization: Bot …`, den Kanal-Pfad und `allowed_mentions`
- 429 mit `retry_after: 0.5`: ein Wiederholungsversuch nach 0,5 s (`Sleep::assertSlept`)
- 429 mit `retry_after: 30`: keine Wiederholung, `DiscordUnavailable`
- 401: `DiscordRejected`
- Inhalt über 2 000 Zeichen wird gekürzt
- `registerCommands()` benutzt die Guild-Route mit `guild_id`, sonst die globale

**Watcher** (`StudyWatcherTest`, `AlertBookTest`):
- Stillstand: erste Minute sendet, zweite nicht (Dedup), nach `repeat_alert_minutes` kommt eine Erinnerung, nach Behebung genau eine Entwarnung
- Stillstand nur mit offenen Läufen (keine Meldung bei fertiger Welle)
- `wave_finished` sendet einmal und nicht erneut in der nächsten Minute
- Pause: `StudyHalted` löst die Sofortmeldung aus, der Watcher in der nächsten Minute sendet nicht doppelt, `resume` löst die Entwarnung aus
- Lagebericht: erscheint nach 6 h, nicht früher, nicht bei fertiger Welle ohne Pause
- Discord wirft: Mail geht trotzdem, `study_events` hat die Zeile, der Watcher wirft nicht
- Mail wirft: Discord geht trotzdem
- Kein Kanal konfiguriert: nur `study_events`

**Heartbeat** (`WorkerHeartbeatTest`): `Looping` für `study` schreibt `study.worker.seen`, für eine andere Queue nicht, und höchstens alle 15 s (zweiter Aufruf in derselben Sekunde schreibt nicht).

**Dashboard** (`ProgressPageControlsTest`): 
- Pausen-Callout zeigt Grund und Zeit
- Knopf „Pausieren“ parkt die Aktion, erst `executeConfirmed()` pausiert
- Ein Nicht-Admin erhält 403 auf `pauseStudy`, auch über `executeConfirmed()` (die Methode prüft selbst)
- Liste der letzten Meldungen zeigt höchstens 10, neueste zuerst
- Worker-Signal wird rot bei Alter über `stale_after_minutes` mit offenen Läufen

**Dead-Man** (`WatchPingTest`): `Http::fake()` sieht genau einen Ping an die URL nach erfolgreichem Lauf. Wirft der Watcher (Datenbank-Fehler simuliert), gibt es keinen Ping. Ist die URL leer, gibt es keinen Aufruf. Ein Timeout des Pings wirft nicht.

**Blindheit** (`StudyBlindnessTest`, Architekturtest): Er liest alle Dateien unter `app/Study` und `app/Study/views` und scheitert, wenn eine davon `SpeakingShares`, `ShareReport`, `ShareTrajectory`, `Metrics\Questions`, `Gini`, `SpeakerShare` oder `Trajectory` nennt. `SpokenTurns` (Zählen ohne Anteile) bleibt erlaubt. Ein zweiter Teil prüft, dass `StatusText::summary()` auf einem Beispiel-Snapshot kein `%` und kein „share“ enthält.

**Bestehende Tests:** `StudyProgressTest` bekommt den Schlüssel `remaining`, die übrigen bleiben. `StudyModuleBoundaryTest` muss ohne Änderung grün bleiben.

### 6.5 Bau-Reihenfolge

1. `study_events` (Migration, Modell). Mit C klären, ob es die Tabelle schon gibt.
2. `StudyProgress`: `remaining`. `StudySnapshot`, `StatusText`, `PauseView`.
3. `ProgressPage` auf `StudySnapshot` umstellen (keine neue Funktion, Tests grün).
4. `AlertBook`, `Alert`, `Notifier`, `StudyWatcher`, `WatchStudy` mit Zeitplan, Dead-Man-Ping.
5. `DiscordClient` und Rate-Limit.
6. `WorkerHeartbeat`, Dashboard-Kopf, Knöpfe, Meldungsliste.
7. `SignatureVerifier`, `InteractionController`, Route, `study:discord-register`.
8. Blindheitstest, README, `.env`-Beispiele.

Die Schritte 1–4 sind auch ohne Discord nützlich (Mail, Dashboard, Dead-Man). Schritt 5 und 7 sind von der HTTPS-Erreichbarkeit abhängig und können nachziehen.

---

## 7. Offene Risiken

1. **HTTPS-Erreichbarkeit für `/status`.** Discord ruft den Endpunkt aus dem Internet auf und verlangt gültiges HTTPS. Der VPS braucht Domain, Zertifikat und eine offene Route. Der Endpunkt verlangt keine Anmeldung und ist nur durch die Signatur geschützt. Ohne HTTPS entfallen `/status` und nur das. Meldungen und Lagebericht laufen weiter. Der Studienserver ist eine eigene Umgebung (Entscheidung 16). Zu klären ist, ob dort überhaupt ein öffentlicher Endpunkt gewollt ist.
2. **Drift-Alarm hängt an A und C.** Hält der Drift-Guard den Lauf nicht an (M9 offen), meldet D keinen Drift. D liest bewusst nicht das Fehlerlog.
3. **3-Sekunden-Grenze bei `/status`.** `StudySnapshot::read()` macht rund ein Dutzend Abfragen. Bei großem `failed_jobs` oder einer langsamen Datenbank kann es knapp werden. Bei Fehlschlag zeigt Discord „Anwendung antwortet nicht“. Abhilfe 1: `read()` 15 s cachen. Abhilfe 2 (bei Bedarf): verzögerte Antwort (`type 5`) und Folgenachricht per Webhook. Beides ist nicht gebaut.
4. **Der Heartbeat misst nur „irgendein Worker“.** Ein einzelner toter Worker bei fünf lebenden fällt nicht auf. Das Tempo (Turns pro Stunde) fängt es indirekt ab. Ohne Looping-Ereignis (anderer Queue-Treiber) bleibt das Signal leer.
5. **Cache muss persistent sein.** Bei `array` oder einem Cache-Neustart senden Alarme erneut, und der Lagebericht kommt sofort. Das stört, gefährdet aber nichts. Bei `php artisan cache:clear` während einer Welle gilt dasselbe.
6. **Doppelte Tabelle `study_events`** mit C (Abschnitt 4.4). Muss vor dem Bau abgestimmt sein.
7. **Mail-Zustellung auf einem VPS** landet oft im Spam oder scheitert an der Absender-Reputation. Mail ist hier das zweite Netz. Der Dead-Man-Ping von healthchecks.io ist das dritte und unabhängige.
8. **Die Stillstandsregel hat einen toten Winkel.** Alle Läufe im Backoff (bis 900 s) oder eine lange Pause lassen den letzten fertigen Turn altern. Das ist gewollt (Welle steht), kann aber bei Pause doppelt melden. Deshalb unterdrückt der Watcher `standstill`, solange `pause !== null` gilt (die Pause ist dann die Ursache und hat ihren eigenen Alarm).
9. **Discord-Token im Klartext in `.env`.** Das Token hat nur das Recht, im Kanal zu schreiben. Rotation im Developer Portal.
10. **Wiederholte Alarme nach einem Neustart des Watchers** sind ausgeschlossen, solange der Cache steht. Die Zustände liegen im Cache, nicht im Prozess.

---

**Nicht gebaut (YAGNI):**
- Pause und Resume per Discord-Befehl
- Gateway und Online-Status des Bots
- Option `wave` für `/status`
- Liste der Worker einzeln
- Kosten- und Budgetanzeige (gehört zu C)
- `/fail`-Ping an healthchecks.io
