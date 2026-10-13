# Import, Export und Statistik-Export – Design

**Datum:** 13.10.2026 · **Branch:** `Studie-JanNiclas-Loosen` · **Status:** freigegeben im Brainstorming

## Ziel

Ein Format für alles: Projekte und Experten einzeln sowie der ganze Bestand lassen sich
exportieren und wieder importieren – als Studienarchiv, als Backup und für einen Umzug.
Dazu ein einfacher Statistik-Export (Rohdaten als CSV) für Prof und Herrn Diehl. Nebenbei:
Prompt-Texte werden nur 24 Stunden aufbewahrt, die Kostenrechnung fällt weg, das
Job-Debug-Fenster zeigt Tokens.

Der alte Projekt-Export (`ProjectExport`, `ProjectImporter`, Route `project.export.json`,
Datei-Upload in „Neues Projekt“, Schema ≤ 10) wird ersetzt, nicht weitergepflegt. Alte
Dateien müssen nicht mehr importierbar sein.

## 1. Datenmodell und Bundle-Format

**Neue Spalten:** `experts.uuid` und `projects.uuid` (eindeutig, beim Anlegen gesetzt).
Eine Migration füllt sie für den Bestand. Sie sind der stabile Schlüssel über Instanzen
hinweg; numerische IDs erscheinen im Bundle nicht als Verweis.

**Umfang eines Bundles:**

| Teil | Inhalt |
|---|---|
| Experten | uuid, name, role, description, avatar_url, Persona-Tags (Kategorie → Wert) |
| Tags | Kategorien mit Scope und Reihenfolge, Werte in Reihenfolge |
| Projekte | Einstellungen, seed, turn_budget, run_config, Summarize-Spalten, Langzeitgedächtnis, Projekt-Tags, Sitzordnung (Experten-UUIDs), Nachrichten, Kurzzeitgedächtnis (`summaries`), Turn-Messung (`job_logs`), Prompt-Logs (so wie gespeichert, also meist mit gekürztem Prompt) |
| Gruppen | nur global: Gruppennamen in Reihenfolge mit den Projekt-UUIDs darin |
| Fragen | nur global: Fragen und Vorlagen mit Notizen und Position; Tag- und Gruppen-IDs in der Definition auf Namen umgeschrieben |
| Nutzer | **nie** – der Importierende wird Besitzer |

**Container:** ZIP mit

```
manifest.json          schema_version, exported_at, Art (project|expert|global), Zählungen
tags.json
experts.json
groups.json            (nur global)
questions.json         (nur global)
projects/<uuid>.json   ein Projekt komplett
```

In einer Projektdatei verweisen Nachrichten, Turns und Prompt-Logs auf Experten per UUID
und untereinander über Positionen/lokale Schlüssel innerhalb der Datei (Turn → seine
Prompt-Logs, Langzeit-Wasserzeichen → Nachricht), nie über Datenbank-IDs. Zeitstempel
(`created_at`, `started_at`, `finished_at`) werden mitgeführt und beim Import übernommen.

## 2. Export und Import

**`BundleExporter`** nimmt eine Auswahl (Projekte, Experten oder global) und schreibt die
ZIP in ein Temp-Verzeichnis. Abhängigkeiten werden immer mitgezogen: ein Projekt bringt
seine Experten und alle benutzten Tag-Kategorien/-Werte mit, ein Experte seine Tags. Gruppen
und Fragen nur beim globalen Export. Auslieferung als Download-Stream.

**`BundleImporter`** liest eine ZIP in fester Reihenfolge:

1. **Tags** per Name abgleichen (Namensregel `TagNames::same()`); fehlende Kategorien und
   Werte werden **angelegt**.
2. **Experten** per UUID: vorhanden → übersprungen; neu → angelegt, Tags gesetzt.
3. **Projekte** per UUID: vorhanden → übersprungen; neu → komplett angelegt (Besitzer und
   Contributor = Importierender), `run_config` bleibt erhalten.
4. **Gruppen** beim importierenden Nutzer anlegen oder per Name wiederverwenden, Projekte
   einsortieren.
5. **Fragen** als eigene Fragen des Importierenden; Vorlagen nur, wenn er Admin ist (sonst
   als normale Fragen). Namen werden zurück in IDs übersetzt; was nicht auflösbar ist, fällt
   wie heute bei gelöschten Tag-Werten heraus.

Jedes Projekt ist eine eigene Transaktion. Ergebnis ist ein **Bericht** je Typ: angelegt,
übersprungen (mit Grund), fehlgeschlagen (mit Grund).

## 3. Oberfläche und Befehle

```bash
php artisan bundle:export {path} [--project=ID]* [--expert=ID]*   # ohne Auswahl: global
php artisan bundle:import {path}
php artisan study:export-turns {path} [--group=ID]* [--pipeline=NAME]*
php artisan prompts:prune
```

- **Projekt:** „…“-Menü von „Projekt bearbeiten“ → „Exportieren“ (Bundle mit diesem Projekt).
- **Experte:** „…“-Menü im Experten-Flyout → „Exportieren“.
- **Einstellungen → Import/Export** (nur Admins): „Alles exportieren“ und ein Upload, der
  jedes Bundle annimmt, danach der Bericht als Callout.
- **Statistik-Seite:** „…“-Menü einer Frage (auch Vorlage) → „Rohdaten exportieren (CSV)“
  für genau die Läufe, die die Frage auswählt.
- Entfällt: Datei-Upload in „Neues Projekt“, `project:cost`.

**Rechte:** Projekt-Export mit `access-project`; Experten-Export für alle angemeldeten
Nutzer; globaler Export und jeder Import nur `admin` (Gate in jeder Aktion); Statistik-CSV
für die Läufe, die der Nutzer sehen darf.

## 4. Statistik-Export

ZIP mit drei CSV-Dateien (UTF-8, Komma, Kopfzeile), verbunden über UUIDs.

**`runs.csv`** – eine Zeile pro Lauf: `run_uuid`, `title`, `description`, `pipeline`,
`model`, `seed`, `turn_budget`, `group` (Sidebar-Gruppe des Exportierenden), **eine Spalte
pro Projekt-Tag-Kategorie** (Wert), `turns_spoken`, `turns_failed_without_speaking`.

**`participants.csv`** – eine Zeile pro Lauf und Agent: `run_uuid`, `expert_uuid`, `name`,
`role`, `seat`, **eine Spalte pro Persona-Tag-Kategorie**, `turns`, `turn_share`, `words`,
`word_share`, `words_per_turn` (aus `SpeakingShares`; Agenten mit 0 Turns enthalten).

**`turns.csv`** – eine Zeile pro Turn inkl. fehlgeschlagener: `run_uuid`, `turn_index`,
`status`, `spoken` (1 wenn `words IS NOT NULL`), `expert_uuid`, `seat`,
`addressee_expert_uuid`, `words`, `chars`, `thought_words`, `thought_chars`, `tokens_in`,
`tokens_out`, `tokens_reasoning` (Summe der Aufrufe des Turns), `selector`, `fallback`,
`tie_broken`, `speaker_bid`, `judge_label`, `selection_json`, `error`.

Regeln: Länge in Wörtern/Zeichen, nie Tokens; `NULL` bleibt leer, nie 0; Tag-Spalten heißen
wie die Kategorie und enthalten den Wert; Laufauswahl folgt dem Läufe-Filter der Frage
(Tags, Pipelines, Modelle, Gruppen), Turn-Fenster und Personen-Filter gelten **nicht**.

## 5. Prompt-Aufbewahrung, Tokens, Kosten

- `PROMPT_RETENTION_HOURS` (Default 24) in `.env`, gelesen über `config/discussion.php`;
  `0` oder leer = nie kürzen.
- `prompts:prune` ersetzt `prompt_logs.prompt` älter als das Fenster durch
  `[prompt removed after 24 h retention]` (Stundenzahl aus der Einstellung). Antwort,
  Reasoning, Tokens, Status, Fehler bleiben. Wiederholbar. Stündlich per Scheduler
  (`routes/console.php`); `composer dev` bekommt `schedule:work`, `service.example` einen
  Timer.
- Bestand bereits am 13.10.2026 gekürzt (16 404 Prompts, DB 815 → 55 MB nach `VACUUM`).
- Job-Debug-Fenster: pro Aufruf `tokens_in`, `tokens_out`, `tokens_reasoning`, pro Turn die
  Summe.
- Entfällt: `RunCosts`, `CostReport`, `CostLine`, `ShowProjectCost` (`project:cost`), die
  Kostenzeile in `project:run`, `price` in `config/ai.php`, zugehörige Tests und Doku.

## 6. Fehlerbehandlung

- Keine ZIP, kein Manifest, unbekannte Schema-Version → Abbruch vor jedem Schreibzugriff.
- Projekt mit unbekannter Pipeline oder unbekanntem Modell → übersprungen mit Grund (kein
  stiller Fallback auf den Standard).
- Projekt mit Experten-UUID, die weder in Datei noch DB existiert → übersprungen.
- Projekt scheitert beim Schreiben → Rollback nur dieses Projekts, „fehlgeschlagen“.
- ZIP-Sicherheit: nur erwartete Dateinamen, kein `..`, keine absoluten Pfade, Obergrenze
  für die entpackte Größe.

## 7. Tests

- Rundreise: Export → Import in leere DB → Gleichheit aller Teile und gleiche
  `SpeakingShares`.
- Wiederholter Import → keine Duplikate, alles „übersprungen“.
- Einzel-Bundles ziehen Experten und Tags mit.
- Fehlerfälle: kaputte ZIP, unbekannte Pipeline, fehlender Experte, `..`-Pfad.
- Rechte für Import, globalen Export, Projekt-Export.
- Statistik-CSV an synthetischen Extremfällen (0 Turns, fehlgeschlagener Turn mit/ohne
  Beitrag, `NULL`-Tokens, Tag-Spalten).
- `prompts:prune`: kürzt nur Älteres, behält Antworten/Tokens, wiederholbar, `0` = aus.

## Reihenfolge

1. UUIDs, `prompts:prune` + Scheduler, Tokens im Debug-Fenster, Kosten raus
2. Bundle-Export und -Import mit Konsolenbefehlen
3. Oberfläche: Einstellungen → Import/Export, Menüeinträge, alten Import entfernen
4. Statistik-CSV mit Befehl und Menüeintrag
