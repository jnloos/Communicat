# Teilprojekt B „Planung“: Manifest, Planer, Anlegen, Prüfen

Stand 15.11.2026. Zweites von sechs Teilprojekten (A Core-Härtung, **B Planung**, C Steuerung, D Überwachung, E Export/Auswertung, F Betrieb). Grundlage: Entscheidungen 1–17 und Maßnahmenkatalog in `2026-11-12-studien-engine-brainstorming-notizen.md`. Die Skizze `2026-11-12-studien-engine-ablauf-skizze.md` gilt nur dort, wo sie den Entscheidungen 11–17 nicht widerspricht. Vorausgesetzt wird A (`2026-11-15-studie-core-haertung-design.md`): `OpenProject::handle(..., ?string $uuid)` (im Code schon vorhanden), Persona-Sperre und Provenienz in `run_config`.

> **Abgleich mit C und D (15.11., Orchestrator):** C (`2026-11-15-studie-steuerung-design.md`) braucht
> `study_waves` **nicht**: Pause ist eine Datei, der Breaker lebt im Cache. `study_waves` gehört allein B,
> `study_events` allein D. Den Tag `superseded` setzt C; B legt ihn nicht an und liest ihn nicht. `ApplyWave`
> läuft **nicht** auf der `study`-Queue, sondern auf der Standard-Queue der App: Der `Queue::looping`-Hook von C
> hält pausierte `study`-Worker an, und das Anlegen soll weder davon noch von 3.072 wartenden Turn-Jobs blockiert
> werden.

## Ziel

Aus einer festen Studiendatei (Manifest) und einem Szenariobereich entstehen reproduzierbar die Läufe einer Welle als gewöhnliche Communicat-Projekte und -Experten:

1. Eine **reine Funktion** `Laufschlüssel → Besetzung` (ohne DB) legt Namen, Titel, Beschreibungen, Sitze, Seed und Tags fest.
2. **Anlegen ist idempotent.** Zweimal anlegen ergibt dasselbe, ein abgebrochenes Anlegen wird beim nächsten Aufruf fortgesetzt.
3. **Welle 2 ändert nichts an Welle 1**, und kein voller Name kommt je in zwei Besetzungen vor.
4. `study:check` rechnet den Plan neu und vergleicht ihn mit jedem angelegten Lauf.

Alles liegt in `app/Study`. Der Core nennt das Modul nicht (`StudyModuleBoundaryTest`) und wird nicht geändert.

## Nicht Teil von B

- Pausen-Flag, Breaker, ein Job pro Turn, Wellenzustand „läuft/fertig“, Budget (C). C erweitert die Tabelle `study_waves` aus Abschnitt 5 um eigene Spalten.
- Preflight, Kostenschätzung, Ereignisprotokoll `study_events`, Fertig-Meldung (C/D). Die Vorschau zeigt keine Kosten.
- Ersatzläufe (`superseded`, Versuchsindex). B reserviert nur die Schlüsselregel dafür (2.1).
- Exportspalten wie `focus seat`, Vornamen-ID, `commit` (E). B setzt nur die Tags, aus denen E liest.
- Pagination von Sidebar und Listen (weiter ohne Zuordnung).

## 1. Manifest

### 1.1 Form

Eine JSON-Datei je Studie (`main` oder `probe`). Sie enthält von Anfang an alle Szenarien, die es schon gibt; spätere Szenarien dürfen hinten angehängt werden (Hash-Regel 1.3).

```json
{
  "schema": 1,
  "study": "main",
  "master_seed": 917263,
  "model": "openai-gpt-6-luna",
  "turn_budget": 30,
  "pipelines": ["ScorePipeline", "OrchestratorPipeline", "BlindQualityPipeline"],
  "attributes": {
    "Role":   { "self": "You are :value.",      "roster": ":value" },
    "Gender": { "self": "You are a :value.",    "roster": ":value" },
    "Origin": { "self": "You are from :value.", "roster": "from :value" }
  },
  "genders": { "G+": "man", "G-": "woman" },
  "subgroups": {
    "DE": { "ethnicity": "E+", "country": "Germany" },
    "FR": { "ethnicity": "E+", "country": "France" },
    "EN": { "ethnicity": "E+", "country": "United Kingdom" },
    "NG": { "ethnicity": "E-", "country": "Nigeria" },
    "IN": { "ethnicity": "E-", "country": "India" },
    "CN": { "ethnicity": "E-", "country": "China" }
  },
  "first_names": {
    "DE": { "G+": [{ "id": "F-M-DE-01", "name": "Thomas" }, "…"],
            "G-": [{ "id": "F-W-DE-01", "name": "Sabine" }, "…"] },
    "…": {}
  },
  "last_names": {
    "DE": [{ "id": "L-DE-01", "name": "Müller" }, "…"],
    "…": []
  },
  "excluded_full_names": ["Thomas Müller"],
  "scenarios": [
    { "id": 1,
      "topic": "Should the school ban phones during breaks?",
      "titles": { "high": "Principal", "low": "Student" },
      "descriptions": { "A": "You know … You do not know …", "B": "…", "C": "…" } }
  ]
}
```

Was nicht im Manifest steht:

- **Kein Ortsfeld** (Entscheidung 15): Origin ist nur das Land aus `subgroups`.
- **Keine Wellen.** Szenariobereich und `decision` kommen beim Upload hinzu (Option bzw. Formularfeld), sonst würde sich der Hash mit jeder Welle ändern.

### 1.2 Prüfung (`Manifest::fromJson`, alle Fehler gesammelt, `InvalidManifest`)

- `schema` = 1, `study` aus `[a-z]+`.
- `master_seed` ist eine positive Ganzzahl, `turn_budget` ≥ 1.
- `model` erfüllt `ModelConfig::exists()`. Jede Pipeline ist in `PipelineRegistry` bekannt, keine doppelt.
- `attributes` hat genau `Role`, `Gender`, `Origin`, jedes Template enthält `:value`.
- `genders` hat genau die Schlüssel `G+` und `G-`.
- Jede Untergruppe hat `ethnicity` aus `E+`/`E-`. Je Vorzeichen gibt es k ≥ 1 Untergruppen mit 12 mod k = 0. Das deckt Entscheidung 13 (3 je Vorzeichen, 4 Namen je Untergruppe) und kleinere Probe-Manifeste ab.
- Je Untergruppe × Gender gibt es ≥ 3 Vornamen (drei Personen einer Besetzung brauchen verschiedene Vornamen), je Untergruppe ≥ 3 Nachnamen.
- **IDs** sind über alle Vor- und Nachnamen eindeutig. Namen sind innerhalb ihrer Liste eindeutig.
- **Szenarien:** `id` lückenlos 1..n in Dateireihenfolge. Titel `high` ≠ `low`. Beschreibungen A, B, C nicht leer und paarweise verschieden.
- **Jeder Text**, der als Attributwert landet (Titel, Gender-Wert, Land, Vor- + Nachname), erfüllt `ValueProblem::of() === null` (≤ 100 Zeichen, kein Zeilenumbruch).
- Jeder String ist getrimmt und in Unicode-NFC (`Normalizer::isNormalized`). Der Reader normalisiert nicht stillschweigend, damit Hash und Inhalt dasselbe meinen.
- **Kapazität:** Der Planer zieht die Namen für alle Szenarien der Datei (2.3). Ist ein Topf erschöpft, ist das Manifest ungültig, mit Angabe von Szenario und Topf. Ein Engpass fällt also schon bei Welle 1 auf, nicht erst bei Welle 3.

### 1.3 Hash-Regel

- `canonical(x)`: Objekte rekursiv nach Schlüssel sortiert, Listen in Reihenfolge, `json_encode` mit `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.
- `core_sha256 = sha256(canonical(manifest ohne "scenarios"))`.
- `scenario_sha256(i) = sha256(canonical(scenarios[i]))`.
- `manifest_sha256 = sha256(canonical(manifest))`, nur als Beleg.

**Annahme einer Welle N** (`WaveRules`), je `study`:

| Fall | Regel |
|---|---|
| N = 1 | Bereich beginnt bei Szenario 1, `decision` optional. |
| N > 1 | Welle N−1 ist angelegt (`applied_at` gesetzt). `core_sha256` ist gleich. Für jedes Szenario i ≤ letztes Szenario von N−1 ist `scenario_sha256(i)` gleich dem der gespeicherten Kopie. Bereich beginnt bei letztes(N−1)+1. `decision` ist nicht leer. |
| Welle N gibt es schon | Nur mit gleichem Bereich und gleichem `manifest_sha256`. Das setzt ein abgebrochenes Anlegen fort, sonst wird abgelehnt. |

Die vollständige Identität der Datei (Skizze) ist ein Sonderfall dieser Regel. Noch nicht geplante Szenarien dürfen bis zu ihrer Welle geändert oder angehängt werden; die Präregistrierung friert sie ohnehin ein.

## 2. Reiner Planer

### 2.1 Begriffe und Schlüssel

- **Töpfe**, fest geordnet: P0 = G+E+, P1 = G+E−, P2 = G−E+, P3 = G−E−.
- **Block** b = 4·f + d + 1 (1..16), f = Topf der Fokus-Persona, d = Topf des Duos.
- **Statuskombination** in fester Reihenfolge `hh, hl, lh, ll` (Fokus, Duo).
- **Instanz** = (Szenario s, Block b). Sie hat 4 Statuskombinationen × |pipelines| Läufe, in der Hauptstudie 12.
- **Laufschlüssel:** `{study}:s{s:02}:b{b:02}:{fs}{ds}:{Pipeline}`, z. B. `main:s03:b07:hl:ScorePipeline`. Ein späterer Ersatzlauf (C/E) hängt `:a2`, `:a3` an. Versuch 1 hat kein Suffix, damit seine uuid stabil bleibt.
- **Profil-Tag** `S{±}G{±}E{±}` mit dem Minuszeichen U+2212, wie die bestehenden Zellen und der `ProgressPageTest` (`S+G+E+`, `S−G−E−`).

### 2.2 Zufall (`Draws`, nie globaler RNG)

```
bytes(domain, ...parts) = hash('sha256', implode("\x1F", [domain, master_seed, ...parts]), binary: true)
shuffle(list, domain, ...parts) = (new Randomizer(new Xoshiro256StarStar(bytes(...))))->shuffleArray(list)
```

Das ist dasselbe Muster wie `SeededShuffle` und `QueueStudyRuns`. `SeededShuffle` selbst braucht `Expert`/`Project` und taugt daher nicht für eine reine Funktion. Jede Ziehung hat eine eigene Domäne:

| Was | Domäne und Teile |
|---|---|
| Projekt-Seed | `1 + hexdec(substr(bin2hex(bytes('seed', key)), 0, 8)) % 2_000_000_000`, gleicher Wertebereich wie `Project::booted()` |
| Sitze | `shuffle(['focus','duo1','duo2'], 'seats', key)`, Index + 1 = Sitz. Unabhängig je Lauf, auch zwischen den Pipelines einer Instanz (Entscheidung 11), ohne Balancierung |
| Namensreihenfolge je Gender × Untergruppe | `shuffle(alle Paare Vorname×Nachname nach ID sortiert, 'names', g, sub)` |
| Untergruppen der Duo-Plätze | `shuffle(Rest-Multimenge, 'duo-subgroups', s, P)` |

Die Laufzeit-Stabilität dieser Ziehungen sichert der Golden-Test (6).

### 2.3 Untergruppen und Namen

**Plätze** je Szenario s und Topf P:

- 4 Fokusplätze: die Blöcke mit f = P.
- 8 Duoplätze: die Blöcke mit d = P, je `duo1` und `duo2`.
- Kanonische Reihenfolge: Block aufsteigend, darin `focus`, `duo1`, `duo2`.

**Untergruppe** (Sub = die k Untergruppen des Vorzeichens von P in Manifestreihenfolge):

- Fokusplatz mit Duo-Topf d: `Sub[(4·(s−1) + d + P) mod k]`. Über je drei aufeinanderfolgende Szenarien ist die Untergruppe der Fokus-Persona je Topf exakt balanciert (Pflichtbalance aus Entscheidung 9).
- Duoplätze: Die Multimenge „je Untergruppe 12/k“ minus die Fokusplätze wird mit `duo-subgroups` gemischt und den 8 Duoplätzen in kanonischer Reihenfolge zugeteilt. Ergebnis: je Szenario und Topf genau 12/k Namen je Untergruppe (Entscheidung 13).

**Namen.** Szenarien werden **immer ab 1** in aufsteigender Reihenfolge durchlaufen, darin die Instanzen nach Block und die Plätze kanonisch. Für jeden Platz mit (g, sub) nimmt der Planer den ersten Kandidaten der Reihenfolge `names(g, sub)`, der:

- nicht in `excluded_full_names` steht,
- noch nirgends vergeben ist (Menge U über alle Töpfe; verglichen wird der volle String „Vorname Nachname“),
- in dieser Instanz weder Vornamen noch Nachnamen mit einer schon gesetzten Person teilt (sonst wirkt das Duo wie eine Familie, oder der Name in der Anrede ist mehrdeutig).

Gibt es keinen solchen Kandidaten, ist das Manifest ungültig (1.2).

Daraus folgt: Die Besetzung von Szenario s hängt nur vom Kern des Manifests und von s ab. Den Inhalt anderer Szenarien und die Wellengrenzen liest sie nicht. Welle 2 rechnet die Szenarien 1..16 mit und erhält dieselben Namen.

### 2.4 Besetzung eines Laufs

| Platz | Name | Titel | Beschreibung |
|---|---|---|---|
| focus | Fokusplatz von (s, b) | `titles[fs]` (h → high, l → low) | A |
| duo1 | erster Duoplatz von (s, b) | `titles[ds]` | B |
| duo2 | zweiter Duoplatz von (s, b) | `titles[ds]` | C |

- **Persona** = Name + Beschreibung + Role (Titel) + Gender (`genders[g]`) + Origin (Land der Untergruppe).
- **Persona-uuid:** `Uuid::uuid5(NS, canonical([name, description, Role, Gender, Origin]))`, mit `NS = Uuid::uuid5(Uuid::NAMESPACE_URL, 'communicat:study:persona')` (ramsey/uuid ist installiert).
- Eine Persona kommt nur innerhalb ihrer Instanz wieder: 2 Statuszellen × |pipelines| Läufe. Volle Namen wiederholen sich nie über Instanzen.
- **Projekt-uuid:** `uuid5(NS_RUN, key)`, mit `NS_RUN` aus `'communicat:study:run'`.
- **Projekt:**
  - Titel `S03 B07 S+G+E+/S−G−E− ScorePipeline` (steht in keinem Prompt).
  - `description` = `topic`, `model`, `pipeline`, `turn_budget`, `seed`.
  - Experten in Sitzreihenfolge.
  - Sichtbarkeit `all` für Role/Gender/Origin, `none` für jedes andere Attribut.
- **Projekt-Tags:**
  - `study: main`, `wave: 1`, `scenario: S03`, `block: B07`
  - `focus profile: S+G+E+`, `duo profile: S−G−E−`
  - `focus seat: 2` (M12)
- **Persona-Tags:** `study: main`, `first name: F-M-DE-03` (Vorname als Zufallseffekt, Entscheidung 12), `subgroup: DE`.

Bewusst weggelassen:

- **Kein `instance`-Tag.** Eine Instanz ist das Paar `scenario` × `block`; bei 64 Szenarien wären es 1.024 Tag-Werte in jedem Filtermenü. E bildet die Spalte aus den beiden Tags.
- **Kein `focus status`/`duo status`.** Der Status steckt im Profil.
- **Kein Nachnamen-Tag.** Der volle Name ist eindeutig und steht in `participants.csv`.

### 2.5 Invarianten (alle getestet)

1. Je Szenario 16 Instanzen × 4 Statuskombinationen × |pipelines| Läufe. Welle 1 = 16 × 16 × 12 = 3.072.
2. Innerhalb einer Instanz sind Namen und Beschreibungen in allen Läufen gleich. Titel folgen dem Status, Sitze und Seed unterscheiden sich je Lauf.
3. Die Fokus-Persona hat immer Beschreibung A, das Duo B und C.
4. Kein voller Name in zwei Instanzen, über alle Szenarien der Datei. In einer Instanz sind Vor- und Nachnamen paarweise verschieden.
5. Je Szenario und Topf genau 12/k Namen je Untergruppe. Die Fokus-Untergruppe ist über je drei Szenarien je Topf exakt balanciert.
6. `plan(1..m)` ist ein Präfix von `plan(1..n)` für m < n. Ein angehängtes Szenario ändert keine frühere Zeile.
7. Kein globaler RNG: Das Ergebnis ist gleich nach `mt_srand(1)` und nach `mt_srand(2)`.
8. Seeds liegen in 1..2·10⁹, Sitze sind Permutationen von 1..3.

## 3. Schreiben (`WaveWriter`)

**Vorbereitung** (`Catalog`, eine Transaktion, vor dem ersten Lauf):

- Tag-Kategorien und -Werte anlegen, wo sie fehlen. Namen werden über `TagNames::same()` abgeglichen, neue Werte hängen hinten an. Die 8 Profilwerte werden in kanonischer Reihenfolge P0..P3 × S± angelegt, weil die Fortschrittsseite die Zeilen nach `position` ordnet.
- Attributdefinitionen: Role über `PersonaAttribute::role()`, Gender und Origin anlegen, wo sie fehlen.
- **Ablehnung vor dem ersten Schreiben**, wenn eine vorhandene Definition andere Templates hat als das Manifest. Der Prompt wäre sonst nicht der, den das Manifest beschreibt.

**Je Instanz eine Transaktion** (16 je Szenario; das sind die Chunks):

1. **Personas:** über `uuid` suchen. Fehlt eine, wird sie angelegt:
   - `(new Expert)->forceFill(['uuid' => …, 'name' => …, 'description' => …])->save()`
   - `syncAttributeValues([Role, Gender, Origin])`, `syncTags(...)`
   - Ist sie schon da: Name, Beschreibung und Attribute vergleichen. Eine Abweichung bricht ab; das ist nur durch Eingriff von Hand möglich, weil die uuid den Inhalt adressiert. Fehlende Tags werden ergänzt (Tags sind auch bei gesperrten Personas erlaubt, A).
2. **Projekte:** über `uuid` suchen. Ein vorhandenes wird übersprungen. Ein fehlendes legt `OpenProject::handle($owner, $attributes, $experts, $tagIds, $visibilities, $uuid)` an, danach `fileInGroupFor($owner, $group)`.

**Gruppe:** je Welle eine Sidebar-Gruppe des Eigentümers, `Studie main · Welle 1`, deren id in `study_waves` steht. Damit funktioniert `study:queue --group=<id>` unverändert.

**Weitere Eigenschaften:**

- Ein Abbruch hinterlässt nur ganze Instanzen. Ein neuer Aufruf legt den Rest an.
- `applied_at` wird erst gesetzt, wenn alle Läufe existieren.
- **Fremde Namen:** Gibt es einen geplanten vollen Namen schon als Experte mit anderer uuid (Pilot- oder Probe-Persona), wird abgelehnt. Das sichert „kein voller Name doppelt“ auch über Studien hinweg und kostet eine `whereIn`-Abfrage je 1.000 Namen.
- **Eigentümer:** CLI `--user` (`ResolvesActingUser`, sonst der erste Admin), im Leitstand der angemeldete Admin. Es gibt keinen Studien-User (Entscheidung 16).

## 4. Befehle und Upload

### `study:plan`

```
study:plan {manifest} --wave=N --scenarios=FROM-TO [--decision=…] [--user=ID] (--dry-run | --apply)
```

Genau eine der beiden Optionen ist Pflicht.

**`--dry-run`** schreibt nichts und zeigt:

- **Läufe:** gesamt und je Pipeline; neu oder schon vorhanden (uuid-Abgleich).
- **Personas:** geplant, neu, vorhanden.
- **Sitzbalance:** Fokus-Sitz 1/2/3 gesamt und je Pipeline, dazu je Zelle (64) das Minimum und Maximum je Sitz.
- **Untergruppen:** Fokus-Untergruppe × Fokus-Profil.
- **Namenshäufigkeit:** je Vornamen-ID die Zahl der Personas (min/median/max je Gender × Untergruppe); volle Namen doppelt: 0.
- **Prompt:** je Szenario einmal die Partials `persona`, `participants` und `project` der Fokus-Persona von Block 1, `hh`, erste Pipeline.
  - So entsteht er: Diese eine Instanz wird in einer Transaktion geschrieben und danach zurückgerollt, wie die Bundle-Vorschau. Gerendert wird mit `PromptRenderer` und denselben Variablen, die `ThinkAsSpeaker` übergibt. Der Text ist also der echte, und Personas werden nicht gesperrt (kein `run_config`).
- Alle Ablehnungsgründe (1.2, 1.3, 3).

**`--apply`** zeigt dieselbe Übersicht ohne Prompt, legt die Zeile in `study_waves` an oder liest sie, und führt `ApplyWave::dispatchSync()` aus. Fortschritt erscheint je Szenario.

### `study:check [--study=main]`

- Rechnet mit der neuesten gespeicherten Manifestkopie den Plan für Szenario 1..letztes angelegtes Szenario.
- Vergleicht für jeden Laufschlüssel:
  - ob das Projekt existiert;
  - `pipeline`, `model`, `turn_budget`, `seed`, `description`;
  - Experten-uuids in Sitzreihenfolge;
  - Name, Beschreibung und Attribute der Experten;
  - Projekt- und Persona-Tags, Sichtbarkeiten;
  - bei gestarteten Läufen zusätzlich `run_config.cast[].name` und `run_config.model`.
- Vergleicht außerdem jede ältere Welle mit ihrer eigenen Kopie. Das ist der Nachweis „Welle 2 ändert Welle 1 nicht“.
- Meldet Projekte mit `study: main`, die in keinem Plan stehen.
- Listet die verschiedenen `run_config.provenance.commit` mit Zahl der `dirty`-Läufe (aus A).
- Ausgabe: eine Tabelle der Abweichungen. Exit-Code `FAILURE`, sobald es eine gibt.

### Upload im Leitstand

`/study/plan`, Livewire `PlanWavePage`, nur Admins (`Gate::authorize('admin')` in `boot()`, wie `ProgressPage`), mit Link von `/study`.

- **Formular:** Datei (JSON, `UploadLimit`), Welle, Bereich von–bis, `decision`.
- **„Vorschau“** ruft dieselbe Methode `WavePlanning::preview()` wie `--dry-run` und zeigt die Tabellen (`flux:table`) und Prompts.
- **„Anlegen“** öffnet `x-confirm-modal` (`NeedsConfirmation`). Danach wird die Zeile in `study_waves` angelegt und `ApplyWave` auf der `study`-Queue eingereiht. Das Anlegen von 3.072 Läufen dauert länger als ein Web-Request.
- Die Seite zeigt „wird angelegt (n / N)“: n ist die Zahl der Projekte mit dem `wave`-Tag. Fertig ist es, wenn `applied_at` gesetzt ist.

## 5. Wo der Zustand liegt

- **Läufe und Personas:** als gewöhnliche Projekte und Experten, wiedergefunden über die deterministische uuid. Die Zuordnung zu Welle, Szenario, Block und Profil steckt in den Tags. Es gibt **keine Lauftabelle**.
- **Eine neue Modul-Tabelle `study_waves`:**

| Spalte | Inhalt |
|---|---|
| `id` | |
| `study` | string |
| `number` | |
| `first_scenario`, `last_scenario` | Bereich der Welle |
| `decision` | text, nullable |
| `manifest` | longText, kanonische Kopie |
| `manifest_sha256`, `core_sha256` | Hashes |
| `user_id` | nullOnDelete |
| `project_group_id` | nullOnDelete |
| `applied_at` | nullable |
| timestamps | |
| unique | `(study, number)` |

**Warum eine Tabelle und keine Tags oder Dateien:**

- Hash-Regel und `study:check` brauchen die Kopie jeder früheren Welle samt Bereich.
- `decision` muss bei den Daten liegen (Entscheidung 8).
- Eine Datei unter `storage/` fehlt im DB-Backup und im Bundle.
- Ein Tag kann kein Manifest tragen.
- C braucht dieselbe Zeile ohnehin für Zustand, Budget und Pause und erweitert sie.

Die Migration liegt in `app/Study/database/migrations`. `StudyServiceProvider` lädt sie **immer** (wie die Queue-Verbindung), damit das Schema nicht vom Schalter abhängt. Das README bekommt einen Entfernen-Schritt „Tabelle `study_waves` droppen“.

## 6. Klassen, Dateien, Tests

| Datei | Verantwortung |
|---|---|
| `app/Study/config.php` | `tags` um `study`, `block`, `focus seat`, `first name`, `subgroup` ergänzt; `focus`, `duo`, `scenario`, `wave` bleiben |
| `app/Study/Planning/Manifest.php` | JSON lesen, prüfen, kanonisieren; `coreHash()`, `scenarioHash(int)`, `sha256()` |
| `app/Study/Planning/InvalidManifest.php` | Ausnahme mit der Liste aller Probleme |
| `app/Study/Planning/Draws.php` | `bytes`, `int`, `shuffle` mit Domänentrennung (2.2) |
| `app/Study/Planning/NameDraw.php` | Untergruppen und volle Namen je (Szenario, Block, Platz) für 1..n (2.3) |
| `app/Study/Planning/Planner.php` | reine Funktion `plan(Manifest, from, to): list<RunPlan>` |
| `app/Study/Planning/RunPlan.php` | Wert: Schlüssel, uuid, Seed, Sitze, Personas, Titel, Thema, Tags; `toArray()` für Golden-Test und Check |
| `app/Study/Planning/PersonaPlan.php` | Wert: uuid, Name, Vornamen-ID, Untergruppe, Attribute, Beschreibungsbuchstabe, Tags |
| `app/Study/Planning/WaveRules.php` | Hash- und Bereichsregel gegen `study_waves` (1.3) |
| `app/Study/Planning/Catalog.php` | Tag-Werte und Attributdefinitionen sicherstellen und abgleichen, liefert die id-Abbildungen |
| `app/Study/Planning/WaveWriter.php` | idempotentes Anlegen je Instanz-Transaktion (3) |
| `app/Study/Planning/PlanPreview.php` | Vorschau-Zahlen aus den Plänen plus DB-Abgleich, Prompt per zurückgerollter Instanz |
| `app/Study/Planning/PlanCheck.php` | Vergleich Plan ↔ DB für `study:check` |
| `app/Study/Planning/WavePlanning.php` | Fassade für CLI und Leitstand: `prepare()`, `preview()`, `apply()` |
| `app/Study/Models/StudyWave.php` | die Wellenzeile, `manifest()` liefert `Manifest` |
| `app/Study/Jobs/ApplyWave.php` | ruft `WaveWriter` für eine Wellenzeile, setzt `applied_at`; Verbindung `study`, `timeout` 3600 |
| `app/Study/Console/PlanStudyWave.php` | `study:plan` |
| `app/Study/Console/CheckStudyPlan.php` | `study:check` |
| `app/Study/Livewire/PlanWavePage.php` + `views/plan.blade.php` | Upload, Vorschau, Bestätigen |
| `app/Study/database/migrations/2026_11_15_000001_create_study_waves_table.php` | Tabelle |
| `app/Study/StudyServiceProvider.php`, `routes.php`, `lang/{de,en}/plan.php`, `README.md` | Registrierung, Route `study.plan`, Texte, Doku |

**Tests** (alle unter `tests/Feature/Study/Planning/`, Fixtures unter `tests/Feature/Study/fixtures/`, damit sie mit dem Modul verschwinden):

- **Fixtures:**
  - `probe-manifest.json`: frei erfundene Namen und Szenarien, `study: probe`, 6 Szenarien, je Untergruppe × Gender 4 Vornamen, je Untergruppe 6 Nachnamen. Die echten Namen und Szenarien gibt es noch nicht.
  - `tiny-manifest.json`: 1 Szenario, 1 Pipeline, also 64 Läufe.
  - `plan-golden.json`.
- `ManifestTest`:
  - das Probe-Manifest ist gültig;
  - jede Ablehnung aus 1.2 einzeln (doppelte ID, fehlendes C, 12 mod k ≠ 0, zu wenige Vornamen, Kapazität erschöpft, unbekanntes Modell oder unbekannte Pipeline, Zeilenumbruch, nicht-NFC, Lücke in den Szenario-ids);
  - der kanonische Hash ignoriert Schlüsselreihenfolge und Leerraum;
  - ein angehängtes Szenario lässt `core_sha256` und die alten Szenario-Hashes gleich.
- `PlannerGoldenTest`: `plan(tiny, 1, 1)` als JSON ist Byte für Byte gleich `plan-golden.json`. Das pinnt auch `Randomizer` und `Xoshiro256StarStar`. Die Datei wird nur bei einer bewussten Algorithmusänderung neu erzeugt.
- `PlannerInvariantsTest`: jede Invariante aus 2.5, auf dem Probe-Manifest.
- `WaveRulesTest`:
  - Welle 1 muss bei 1 beginnen;
  - Welle 2 braucht Anschluss, `decision`, gleichen Kern und gleiche alte Szenario-Hashes;
  - ein geändertes Szenario 3 wird abgelehnt, ein geändertes noch ungeplantes Szenario angenommen;
  - dieselbe Welle erneut mit gleichem Hash wird angenommen, mit anderem abgelehnt.
- `WaveWriterTest`:
  - legt N Projekte und 6 Personas je Instanz an, mit Tags, Sichtbarkeiten (3× `all`, sonst `none`), Sitzen in Planreihenfolge, Seed und uuids;
  - ein zweites Anlegen ändert keine Zahl und keine `updated_at`;
  - nach Löschen eines Projekts legt ein neues Anlegen genau dieses wieder an;
  - eine abweichende Gender-Definition lehnt ab, bevor etwas geschrieben ist;
  - ein schon vorhandener Name mit fremder uuid lehnt ab;
  - Welle 2 auf einem Manifest mit angehängtem Szenario lässt alle Projekte von Welle 1 unberührt.
- `PlanStudyWaveCommandTest`:
  - `--dry-run` schreibt nichts (Zähler vorher = nachher, `study_waves` leer) und gibt alle Abschnitte aus, einen Prompt je Szenario;
  - ohne oder mit beiden Optionen schlägt der Befehl fehl;
  - `--apply` legt `study_waves` mit `applied_at` an.
- `CheckStudyPlanTest`: ein sauberer Stand ergibt `SUCCESS`. Ein geänderter Seed, ein gelöschter Tag, vertauschte Sitze oder ein fremdes Studienprojekt werden einzeln gemeldet und ergeben `FAILURE`.
- `PlanWavePageTest`:
  - Nicht-Admins bekommen 403;
  - Upload zeigt die Vorschau;
  - Bestätigen legt die Wellenzeile an und reiht `ApplyWave` ein (`Queue::fake`).
- `ProgressPageTest` (bestehend, ein Fall mehr): eine angelegte Probe-Welle erscheint im Raster und im Wellenfilter.
- `StudyModuleBoundaryTest` bleibt grün.

**Reihenfolge des Baus:**

1. `Manifest`, `Draws`, `NameDraw`, `Planner` mit Golden- und Invariantentests.
2. `WaveRules`, Migration, `StudyWave`.
3. `Catalog`, `WaveWriter`, `ApplyWave`.
4. `study:plan`, `study:check`.
5. `PlanWavePage`.
6. README und `CLAUDE.md` (Abschnitt Study module): Befehle, Tags, `study_waves`.

## 7. Offene Risiken

- **Sitze zufällig je Lauf:** Einzelne Zellen können schief verteilt sein (bewusst, Entscheidung 11). Die Vorschau macht es sichtbar, ausgeglichen wird nicht.
- **Vorrat der gierigen Namensziehung:** Mit 16 Vornamen × 40 Nachnamen sind es 640 Paare je Gender × Untergruppe, gebraucht werden bei 64 Szenarien 256. Die Regel „kein Vor- und kein Nachname doppelt in einer Instanz“ verbraucht zusätzlich Kandidaten. Die Kapazitätsprüfung über alle Szenarien der Datei deckt das auf, bevor etwas angelegt ist.
- **Spätere Szenarien:** Sie verändern die Namenszuordnung nie, wohl aber ein geänderter Kern. Wird nach Welle 1 ein Name gesperrt (M13), braucht es ein neues `study` oder eine bewusste neue Studie. Darum muss `excluded_full_names` vor Welle 1 aus dem Probelauf feststehen.
- **Filtermenüs:** `first name` bringt etwa 190 Tag-Werte, `scenario` bis 64. Experten- und Statistikfilter werden lang. Das gehört zur offenen Pagination- und Skalierungsfrage.
- **Unicode:** NFC wird verlangt, nicht hergestellt. `ext-intl` (`Normalizer`) muss auf dem VPS vorhanden sein und in `composer.json` aufgenommen werden (F).
- **`ApplyWave` auf der `study`-Queue:** Stehen noch Läufe einer alten Welle an, wartet das Anlegen dahinter. `study:queue` darf erst nach `applied_at` laufen; die Seite zeigt das, erzwungen wird es nicht.
- **Vorhandene Attributdefinitionen** mit anderen Templates (etwa „You are a woman.“ aus der Gender-Reihe) blockieren das Anlegen. Sie werden vorher entschieden, nicht automatisch überschrieben, weil `run_config` alter Läufe die Templates schon eingefroren hat.
- **Persona-Sperre aus A:** Ein Probelauf sperrt die Probe-Personas für immer. Haben Probe-Namen dieselben Strings wie echte Namen, scheitert das Anlegen der Hauptstudie (Fremd-Namen-Prüfung). Die Probe-Namen müssen deshalb von den echten Namen getrennt sein.
- **Beschreibungen:** Fokus immer A koppelt Fokus und Beschreibung innerhalb eines Szenarios (Entscheidung 10, gewollt). Gehört in die Limitationen.

---

Bewusst weggelassen: Kostenschätzung in der Vorschau, Ersatzläufe, Tags `instance` und `focus/duo status`, Nachnamen-Tag, `study_events`, Preflight.
