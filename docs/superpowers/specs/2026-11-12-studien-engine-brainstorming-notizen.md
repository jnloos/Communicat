# Studien-Engine: Brainstorming-Notizen (12.11.2026)

Arbeitsstand, keine Spec. Quelle: zweistufiges Agenten-Brainstorming (Betrieb, Leitstand-UI, Methodik,
Engine/Daten → Kreuzprüfung). Die Spec entsteht nach den offenen Entscheidungen unten.

## Entschieden

- Engine im Modul `app/Study`: plant (Manifest → Welle), führt aus, überwacht. Nur Hauptstudie, kein Vergleichsarm.
- Wellen fügen Szenarien hinzu (Welle 1 = 16 Szenarien × 64 Zellen × 3 Pipelines = 3.072 Läufe à 30 Turns, gpt-6-luna).
- Netcup-VPS (2 vCPU, 4 GB, 64 GB), unbeaufsichtigt. Alarme: E-Mail + Discord-Webhook (kein Bot).
- Personas inhaltsadressiert: Name × Rollentitel, konstante description, nach erstem Einsatz gesperrt;
  Fokus/Duo ist Eigenschaft des Laufs (Projekt-Tag `focus seat`), nicht der Persona.
- Namen und Szenarien sind Eingabedateien, werden später gemeinsam erarbeitet.

## Im Code bestätigte Befunde

- 401/403/400 → Welle läuft in Minuten leer (Orchestrator: 4xx nicht transient, `TransientFailure.php:19`;
  Score/Blind Quality: `TypeError` aus dem Kindprozess). Leeres Guthaben (429 `insufficient_quota`) → transient,
  Welle läuft nach ~2 h Backoff leer. Kein laufübergreifender Breaker.
- Ein 500er ist im Orchestrator transient, in Score/Blind Quality failed → Fehlerraten je Pipeline nicht vergleichbar.
- systemd `TimeoutStopSec=300` < Job 3600 s → Neustart killt Läufe, Reservierung bis `retry_after` 3900 s. Unit nennt 16 Worker, ~6 passen.
- `uuid` nicht fillable, `HasPublicUuid` setzt Zufallswert → deterministische uuid braucht eine Naht.
- `cast` friert Name + Attributzeilen ein, nicht description und Thema. `participants.csv` liest Tags/Attribute live.
- Persona-Tag `focus persona` (ThesisTemplatesSeeder, Power-Skript, Vorlagen) wird bei Wiederverwendung falsch.
- `.env.study.example`: sqlite, `APP_DEBUG=true`, gpt-5-mini.

## Arbeitspakete

| WP | Inhalt | Prio |
|---|---|---|
| 0 Nicht-Code | 64 Szenarien vorab + Seed-Reihenfolge, 240 Namen + Probe-Namen, OpenAI-Projekt-Key/Prepaid/Limits, VPS, OSF-Präregistrierung | PFLICHT |
| 1 Core-Härtung | uuid-Naht, Freeze (description, Thema, Release, View-Hashes), Persona-Sperre inkl. Löschen/Console/Import, Fehlerklassen im Kindprozess angleichen | PFLICHT |
| 2 Manifest/Planer | Manifest + Reader (sha256), reiner Planer (3 Namen, Sitz-Balance über 3 Sitze), RunWriter idempotent, `study:plan --dry-run/--apply`, `study:check` | PFLICHT |
| 3 Steuerung | `study_waves` (Zustand, Budget, Pause), Pause zwischen Turns, `study:halt`, Breaker, Kostenbremse, Stop-Timeout, Drift-Pause | PFLICHT |
| 4 Überwachung | `study:watch` (Scheduler), Mail + Discord, Dedup, `study_events`, healthchecks.io-Dead-Man's-Switch | PFLICHT |
| 5 Leitstand | `/study` ausbauen: Wellen-Kopf + Knöpfe, Kosten/Budget, Worker-Heartbeat, Blindheits-Architekturtest; Drilldown/Ereignisprotokoll SOLLTE | PFLICHT/SOLLTE |
| 6 Export/Auswertung | `focus seat`, `superseded`, interrupted/Fallbacks; Auswertungsskript an synthetischen Fällen; blindes Aufstockungsskript | PFLICHT |
| 7 Betrieb | MySQL, restic-Backups + Restore-Test, MemoryMax/Swap, Log-Rotation, ext-zstd, Chaos-Probe, Lasttest | PFLICHT |

## Kritischer Pfad

Entscheidungen → Szenarien + Namen → WP1 → WP2 → WP3 minimal → Probelauf (48–96 Läufe, Probe-Namen) →
Poweranalyse neu + Auswertungsskript validiert → Präregistrierung → Chaos-Probe/Preflight auf VPS →
`study:plan --apply` Welle 1 → `study:queue --order-seed`. Parallel: WP7 sofort, WP4/WP5 während WP1–2, WP6 vor dem Probelauf.

## Offene Entscheidungen

1. Namen (Regression) oder „Persona A/B/C“ (ANOVA)?
2. Sichtbarkeit Gender/Herkunft: `own` oder `all` (sieht das Duo die Merkmale der Fokus-Persona)?
3. Paarung: teilen die 3 Pipelines einer Zelle×Szenario Namen/Sitz? Behält die Fokus-Persona zwischen S+ und S− den Namen?
4. Job-Zuschnitt: ein Job pro Lauf (langer Stop-Timeout) oder Turn-Pakete, die sich neu einreihen?
5. Ersatzregel für unvollständige Läufe: neuer Versuch mit neuem Seed (Versuchsindex im Schlüssel) oder ausschließen?
6. Herkunft binär (+ Untergruppe als Spalte) oder vierstufig?
7. Aufstockung: wer startet das Entscheidungsskript, Punktwert oder obere CI-Grenze?
8. Core-Leck (Statistik-Flyout, `/statistics`): organisatorisch oder per Sperre für Studienprojekte?
