# Values konsequent führen und Adjacency auf `addressee` reduzieren: Design

Stand: 26. September 2026. Branch: `Studie-JanNiclas-Loosen`.
Roadmap: `docs/superpowers/specs/2026-09-26-studie-umbau-roadmap.md` (Stücke 1 und 2).

Zwei Vorhaben in einer Spec, weil sie klein sind, dieselben Verzeichnisse berühren und in einem
Zug geprüft werden sollten. Sie sind trotzdem in zwei Commits zu trennen: das erste verschiebt nur,
das zweite ändert nur.

## 1. Ziel

**Stück 1** macht aus `App\Discussion\Values` eine durchgehaltene Einordnung statt einer
Teilmenge. Heute liegen dort fünf Datentypen, während `MemoryView`, `TurnResult` und der
`Purpose`-Enum daneben liegen, obwohl sie derselben Regel folgen.

**Stück 2** entfernt den typisierten Adjacency-Paartyp. Übrig bleibt die eine Angabe, die die
Studie tatsächlich auswertet: wen ein Beitrag anspricht.

Kein Verhalten ändert sich in Stück 1. In Stück 2 ändert sich genau eins: der Paartyp wird nicht
mehr erhoben, nicht mehr gespeichert und nicht mehr angezeigt.

## 2. Getroffene Entscheidungen

| Thema | Entscheidung |
|---|---|
| Einordnungsregel | nach `Values` gehört, was unveränderlich ist, keinen Service kennt und nichts tut außer Werte tragen |
| `Purpose` | wandert mit, obwohl es ein Enum ist: es trägt einen Wert und sonst nichts |
| `TurnPayload` | bleibt, wo es ist — veränderlich, trägt den Turn durch die Pipeline |
| `GenerationLoop` | bleibt — cache-gestützt, also mit Seiteneffekten |
| Exceptions | bleiben, wo sie sind |
| Benennung | `addressee`, nicht `talks_to` und nicht `opens_pair_with` |
| Relation | `belongsTo(Expert::class)` statt `morphTo()` |
| Migrationsform | die Ursprungsmigration wird direkt geändert; keine Folgemigration |
| Altdaten | verfallen. Kein Produktivsystem, keine Datenübernahme — die Datenbank wird neu aufgebaut |
| Paartyp-Historie | wird nicht aufgehoben; die Spalte fällt ersatzlos |

## 3. Stück 1: Values-Konsolidierung

### 3.1 Was sich bewegt

| von | nach |
|---|---|
| `App\Discussion\Memory\MemoryView` | `App\Discussion\Values\MemoryView` |
| `App\Discussion\TurnResult` | `App\Discussion\Values\TurnResult` |
| `App\Discussion\Purpose` | `App\Discussion\Values\Purpose` |

`app/Discussion/Memory/` behält damit nur noch `Memory.php` — das ist richtig so: der Ordner trägt
den Service, nicht dessen Rückgabewert.

### 3.2 Was ausdrücklich bleibt

`TurnPayload` (veränderlich, sammelt während des Turns Zustand ein), `GenerationLoop`
(cache-gestützt), `Memory` (Service mit Datenbankzugriff), `ParseFailure` und `MissingPayloadSlot`
(Exceptions), sowie alles unter `Agents`, `Stages`, `Selectors`, `Pipelines`, `Support` und
`Logging`.

### 3.3 Betroffene Dateien

Zehn Importzeilen in neun Dateien:

- `MemoryView`: `app/Discussion/Memory/Memory.php`, `tests/Unit/Discussion/PromptRendererTest.php`
- `TurnResult`: `app/Discussion/TurnRunner.php`
- `Purpose`: die fünf Agent-Klassen unter `app/Discussion/Agents`, `StudyAgent.php` selbst,
  `tests/Unit/Discussion/StudyAgentTest.php`

`app/Jobs/MessageGenerator.php` liest `->stop` am Rückgabewert, ohne den Typ zu importieren, und
bleibt unberührt. `RecordPromptLog` verwendet `Purpose` nicht direkt.

### 3.4 Was das nicht sein darf

Kein Alias, kein `class_alias`, keine Übergangsklasse am alten Ort. Ein halb verschobener Typ wäre
schlimmer als der jetzige Zustand, weil dann zwei Orte richtig wären.

### 3.5 Abnahme

`php artisan test` läuft unverändert grün und `vendor/bin/pint` ist sauber. Keine Datei unter
`app` oder `tests` importiert noch `App\Discussion\Purpose`, `App\Discussion\TurnResult` oder
`App\Discussion\Memory\MemoryView`, und die drei alten Dateien existieren nicht mehr.
Die Einordnungsregel aus Abschnitt 2 wird in `CLAUDE.md` unter „Data model notes" aufgenommen,
damit die nächste Einordnung nicht wieder Geschmackssache ist.

## 4. Stück 2: Adjacency auf `addressee`

### 4.1 Warum der Paartyp geht

`adjacency_pair_type` hält eines von vier Etiketten (`Frage→Antwort`, `Ansprache→Reaktion`,
`Beitrag→Diskussion`, `Synthese→Diskussion`), selbstberichtet vom sprechenden Modell. Keine
Zielgröße der Studie liest es: der Messplan kennt Turn-Anteil, Gesprächsanteil, deren Verhältnis,
Gini, Entropie, die pipelineinternen Signale und die Übernahme der Position des betitelten
Agenten. Für keine davon wird der Paartyp gebraucht. Er kostet dagegen einen Prompt-Abschnitt,
einen Parser, eine Spalte und vier Konstanten — und als Selbstbericht eines Modells über die eigene
Sprechhandlung wäre er ohnehin kein belastbares Datum.

Was bleibt, ist die Adressierung. Sie trägt weiterhin etwas: aus „wer spricht wen an" lässt sich
später ablesen, ob der betitelte Agent häufiger adressiert wird.

### 4.2 Warum aus dem Morph ein `belongsTo` wird

`nullableMorphs('adjacency_partner')` konnte auf `Expert` oder `User` zeigen. Seit dem Rückbau
gibt es keinen Nutzerbeitrag mehr — der Chat hat keinen Composer, und `Speak` verwirft ein
`U`-Token schon heute (`app/Discussion/Stages/Speak.php:86`). Ein Adressat kann also ausschließlich
ein beitragender Experte sein. Zwei Spalten plus Index für eine Relation mit genau einem
möglichen Zieltyp sind Aufwand ohne Gegenwert.

### 4.3 Schema

Das System läuft nirgends produktiv, es gibt keine Daten, die erhalten bleiben müssen. Deshalb
keine Folgemigration, sondern eine Änderung an `2025_05_30_152442_create_messages_table.php`
selbst: aus

```php
$table->string('adjacency_pair_type', 50)->nullable();
$table->nullableMorphs('adjacency_partner');
```

wird

```php
// Wen dieser Beitrag anspricht. Nur ein beitragender Experte kommt in Frage:
// der Chat hat keinen Composer mehr, Speak verwirft ein U-Token.
$table->foreignId('addressee_expert_id')->nullable()->constrained('experts')->nullOnDelete();
```

Danach `php artisan migrate:fresh` und `php artisan dev:build-suite`. Der Entwicklungsdatenbestand
entsteht ohnehin aus dem Seeder; er ist kein Gut, das eine Migration schützen müsste.

Das ist bewusst anders als bei `2026_09_24_120000_projects_rename_model_keys.php`, das damals
additiv gebaut wurde. Solange kein Studienlauf existiert, ist die Ursprungsmigration die ehrlichere
Darstellung: das Schema wird gelesen wie ein Entwurf, nicht wie eine Historie von Reparaturen.

### 4.4 Code

**`app/Models/Message.php`** — die vier `PAIR_*`-Konstanten entfallen, `adjacencyPartner(): MorphTo`
wird zu `addressee(): BelongsTo`. Der Kommentarblock, der die Morph-Richtung erklärt, schrumpft
auf einen Satz.

**`app/Discussion/Values/Contribution.php`** — aus
`(string $text, ?string $partnerToken, ?string $pairType, Completion $completion)` wird
`(string $text, ?string $addresseeToken, Completion $completion)`.

**`app/Discussion/Stages/Speak.php`** — `PAIR_TYPES` und `pairType()` entfallen, `partnerToken()`
heißt `addresseeToken()`. Der `STEUERUNG`-Block im Prompt verliert die `PAARTYP`-Zeile; der Marker
selbst bleibt vorerst, er fällt erst mit Stück 3 der Roadmap.

**`app/Discussion/Stages/PersistMessage.php`** — die Zuweisung eines Vorgabe-Paartyps entfällt
ersatzlos. Bisher wurde ein fehlender Paartyp still zu `Beitrag→Diskussion`; solche stillen
Ersetzungen verschwinden mit der Spalte.

**`app/Events/MessageGenerated.php:38-48`** — der Aufbau, der den Morph in `addressed_expert_id`
und `addressed_user_id` auffächert, wird zu einer Zeile. `addressed_user_id` entfällt aus der
Nutzlast.

**`app/Services/ProjectTransfer/ProjectExport.php:57-61`** und **`ProjectImporter.php:72-83`** —
drei Exportfelder werden zu einem (`addressee_expert_id`). Keine Abwärtskompatibilität für alte
Exportdateien: der Importer liest die alten Schlüssel schlicht nicht mehr. Eine Datei aus dem alten
Format importiert dadurch weiterhin ohne Fehler, nur ohne Adressaten — hinnehmbar, weil die Daten,
auf die es ankommt, erst mit der Studie entstehen.

**`resources/views/components/projects/chat-message.blade.php:12-20`** — der `instanceof`-Zweig für
`User` entfällt; übrig bleibt die Prüfung, dass der Adressat nicht der Sprecher selbst ist.

**`resources/views/livewire/debug/job-debug-panel.blade.php:194-200`** — die Paartyp-Anzeige
entfällt, die Adressatenzeile bleibt.

### 4.5 Sprachdateien

Geprüft: keine Sprachdatei führt Schlüssel für die Paartypen — das Debug-Panel gibt den
Spaltenwert roh aus. In `lang/de` und `lang/en` entfällt also nichts. Der Schlüssel für die
Adressatenanzeige (`chat.message.addressed`) bleibt unverändert.

### 4.6 Tests

- `tests/Unit/Discussion/SpeakTest.php` — die Zusicherungen auf `pairType` entfallen,
  `partnerToken` heißt `addresseeToken`. Neu dazu: eine `PAARTYP`-Zeile in der Modellantwort wird
  ignoriert statt gespeichert.
- `tests/Unit/Discussion/PersistMessageTest.php` — der Test auf den Vorgabe-Paartyp wird zu einem
  Test darauf, dass ein Plenumsbeitrag ohne Adressat gespeichert wird.
- `tests/Unit/MessageGeneratedPayloadTest.php` — der Fall „Partner ist ein Nutzer" entfällt, weil
  er nicht mehr eintreten kann.
- Die bestehenden Export-/Importtests werden auf das neue Feld gezogen. Kein Test auf das alte
  Exportformat: es wird nicht mehr unterstützt.

Kein Migrationstest. Es gibt keine Datenübernahme, die schiefgehen könnte — das Schema entsteht
frisch, und dass es entsteht, prüft jeder Test der Suite ohnehin bei jedem Lauf.

### 4.7 Abnahme

`php artisan test` grün, `vendor/bin/pint` sauber, `php artisan migrate:fresh` gefolgt von
`php artisan dev:build-suite` läuft durch, und weder `adjacency` noch `PAIR_` kommt in `app`,
`resources` oder `tests` noch vor. Dazu eine Sichtprüfung im Chat: eine Nachricht mit Adressat
zeigt weiterhin „Angesprochen: …", eine ohne zeigt nichts.

## 5. Nicht Teil dieser Spec

Die Marker-Konstanten und das Regex-Parsing in `Speak` bleiben zunächst stehen — sie fallen
vollständig mit Stück 3 der Roadmap (Structured Output). Diese Spec nimmt dem `STEUERUNG`-Block
nur die `PAARTYP`-Zeile.
