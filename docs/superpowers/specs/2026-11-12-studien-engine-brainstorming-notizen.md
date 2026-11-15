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
- **Echte Namen** (Regression mit Name als Zufallseffekt), keine „Persona A/B/C“.
- **Baukasten statt handgepflegter Personas:** Pools (Namen je Gender × Herkunft à 60, Orte je Herkunft,
  Titel aus dem Szenario, szenariospezifisch erlaubt) sind das Studienmaterial. Der Planer baut je Lauf drei
  Personas zusammen und legt sie als normale Experten an (find-or-create über den vollen Inhalt), gesperrt,
  mit Tags `pool entry: M-EU-017`, `study: main`. Viele Datensätze sind in Ordnung; kein Core-Umbau.
  Name und Herkunft werden nicht unabhängig gezogen (Herkunft steckt im Namen).
- **Sichtbarkeit `all`** für Role, Gender, Origin: eigene Zeilen im Persona-Text und in der Teilnehmerliste
  der anderen (bestehende Attribute, unverändert).
- **Gepaart:** Die 3 Pipelines einer Instanz (Zelle × Szenario) teilen Namen und Sitze, nur der Seed
  unterscheidet sich. Auswertung braucht Zufallseffekt `instance`; Export trägt den Instanzschlüssel als Tag.
- **Ein Job = ein Turn:** `RunStudyProject` führt einen Turn aus und reiht den Lauf danach neu ein
  (`WithoutOverlapping` je Projekt bleibt). Kurzer `TimeoutStopSec`, Pause/Notbremse greifen sofort.
- **Unvollständige Läufe werden fortgesetzt**, bis 30 gesprochene Turns erreicht sind (fehlgeschlagene Turns
  zählen nicht, Zählregel unverändert). Ersatzlauf nur, wenn ein Lauf endgültig aufgegeben wird
  (Grenze, z. B. > 10 gescheiterte Turns, in der Präregistrierung festlegen); dann `superseded` + Versuchsindex.
- **Herkunft gemischt mit Untergruppe:** E+ und E− bestehen je aus mehreren Herkünften zu gleichen Teilen
  (z. B. E−: nigerianisch, indisch, chinesisch, arabisch, je 15 der 60 Namen). Hypothesen binär; die Untergruppe
  ist Feld jedes Pooleintrags und Spalte im Export (explorativ). Folge: Poweranalyse neu prüfen (gegenläufige
  Untergruppen können den Haupteffekt verkleinern), Untergruppen balanciert über Zellen und Status ziehen.
- **Aufstockung ist eine manuelle Entscheidung des Forschers, nicht Teil der Engine.** Ein Manifest pro Welle
  (z. B. W1 = Szenarien 1–16, W2 = 17–32, W3 = 33–64), hochgeladen im Leitstand (PFLICHT-UI: Upload →
  Vorschau mit Laufzahl, neuen Personas, Balance, Kosten → Bestätigen) oder per CLI. Ein Folgemanifest muss
  Pools, Master-Seed und Modellkonfiguration der früheren Wellen exakt teilen, sonst Ablehnung; es fügt nur
  Szenarien hinzu. Nach dem Upload läuft die Welle 1–2 Tage ohne Eingriff bis zum Ende (Fertig-Meldung +
  Prüfbericht per Mail).

## Bedenkenmuster: Skalierung der Ansichten

Jede Liste muss mit der Größe der Studie zurechtkommen: paginiert (höchstens 10 je Seite, auch bei aktivem
Filter), serverseitig gesucht und gefiltert, nie ungebremst `->get()` auf Experten oder Projekte.
Betroffen: `ExpertList` (lädt alle samt Tags/Attributen), `SelectContributors` (lädt alle),
**Sidebar `ProjectNavigation`** (3.072 Läufe je Welle als Einträge – größter Engpass), Statistikseite
(~8 Abfragen je Lauf). Unkritisch: Namensfilter der Statistik (`distinct` über 240 Namen).

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

## Entscheidung 9: Hintergründe (12.11. nach zweiter Agentenrunde)

Jedes Szenario hat drei **neutrale, gleichwertige Hintergründe** im Communicat-Muster „You know … / You do not
know …“ (Schutz vor Halluzination), etwa gleich lang, Perspektive statt exklusiver Schlüsselinformation. Sie werden
per Seed zufällig zugeteilt (Fokus bekommt je Zelle jeden Hintergrund etwa gleich oft), am Lauf als Tag
`focus background` protokolliert und können als Kovariate ins Modell. Der Probelauf prüft, ob die zusätzliche
Streuung tragbar ist; sonst Umschalten auf einen gemeinsamen Text (nur Manifest-Änderung).

Befund aus der DB (Praktiker): In den bisherigen Zellen 325–580 sitzt die Fokus-Persona immer auf Sitz 1 und hat
immer den ersten Wissens-Slot → Fokus, Sitz und Wissen vermengt; gehört in die Limitationen der Pilotbefunde.

Vereinfachungen aus der zweiten Runde: eine feste Studiendatei (Hash), Welle = Szenariobereich + `decision`
beim Upload; Namen je Pool einmal gemischt und der Reihe nach abgearbeitet (Name ist Zufallseffekt); Pflicht-
Balancen nur Fokus-Sitz `(Zelle + Szenario) mod 3` und Herkunfts-Untergruppe der Fokus-Persona; Ort fest am
Namen; Warteschlange per `--order-seed` gemischt. Namen als CSV, Szenarien als Datei, Manifest daraus generiert.

## Entscheidung 10: Beschreibungs-Triple und Viererblöcke (ersetzt die Zufallszuteilung aus 9)

- Je Szenario ein **festes Triple** aus drei Beschreibungen (A, B, C) im Muster „You know … / You do not
  know …“. **Fokus bekommt immer A, das Duo B und C** – in allen 64 Zellen des Szenarios. Damit ist der
  Hintergrund innerhalb eines Szenarios konstant, auch für Gender- und Herkunftsvergleiche; zwischen Szenarien
  fängt ihn der Zufallseffekt Szenario auf.
- **Viererblöcke:** 64 Zellen = 16 Blöcke (Fokus G×E × Duo G×E) × 4 Statuskombinationen. Innerhalb Block ×
  Szenario gleiche drei Namen, gleiche Sitze, gleiche Beschreibungen; nur Titel (und Seed) wechseln →
  Vorher-Nachher-Vergleich derselben Persona für H1.1 und H2.1. Status × Untergruppe ist dadurch automatisch
  balanciert.
- Sitz rotiert getrennt von der Beschreibung: `(Block + Szenario) mod 3`.
- Persona = Beschreibung + Name + Titel + Herkunft (viele Datensätze, rückführbar auf „Szenario n, A/B/C“).
- Schreibregel nur noch als Empfehlung: keine groben Rollenhinweise in den Beschreibungen (z. B. „someone new“).

## Entscheidung 11: Sitze rein zufällig je Lauf (ersetzt `(Block + Szenario) mod 3`)

Vor jedem Lauf werden die drei Sitze per Seed zufällig verteilt, unabhängig für jeden der 12 Läufe einer
Besetzung (auch zwischen den Pipelines), ohne Balancierung je Zelle. Der Sitz der Fokus-Persona geht als fester
Faktor ins Modell (Handout 12.11., §2 „Sitz zufällig“, §4). Bewusst in Kauf genommen: etwas Power-Verlust beim
Statusvergleich innerhalb einer Besetzung und mögliche Schieflagen je Zelle.

Handout-Stand 12.11. 15:37: Beschreibung A/B/C, E±-Zusammensetzung, Besetzung als Zufallseffekt sind drin;
offen nur ein Satz in §5 (Poweranalyse bildet das Blockdesign ab) und „Deine Position“ vs. englische Prompts.

Handout-Stand 12.11. 23:28: Der §5-Satz ist drin. Neu: **kein Name in zwei Besetzungen** (48 neue Namen je
Szenario, 12 je Pool; gesamt 768 / 1.248 / 3.072 bei 16 / 26 / 64 Szenarien), Zufallseffekt nur noch die
Besetzung. Aufstockung 16 → 26 → 64 (Wellen 1–16, 17–26, 27–64). Weiter offen: „Deine Position“ vs. englische
Prompts.

## Entscheidung 12: Eindeutig ist der volle Name, Vornamen wiederholen sich (12.11. abends)

- Kein **voller Name** (Vor- + Nachname) kommt in zwei Besetzungen vor. **Vornamen** stammen aus einer festen
  Liste häufiger Vornamen je Gender × Untergruppe und wiederholen sich über Besetzungen hinweg.
- Grund: 768 verschiedene Vornamen je Pool erzwingen seltene Namen, die Klasse und Alter mitsignalisieren
  (Gaddis 2017). Häufige Vornamen halten das Gender-/Herkunftssignal konstant.
- Folge für die Auswertung: **Vorname als gekreuzter Zufallseffekt** neben der Besetzung; Gegenprobe ohne ihn.
  Das Handout (§3 „ein eigener Namenseffekt … entfällt“, §4, §6 „Namen … wiederholen sich nicht“) ist
  entsprechend anzupassen.
- Folge für die Engine: Manifest führt Vorname und Nachname getrennt (Vorname mit eigener ID); die Prüfung
  erzwingt Eindeutigkeit des vollen Namens über alle Wellen; Export braucht eine Vornamen-Spalte.

## Entscheidung 13: Untergruppen je drei, E+ deutsch / französisch / englisch (12.11. abends)

- **E+:** deutsch, französisch, englisch. **E−:** nigerianisch, indisch, chinesisch.
- Je Szenario und Pool 12 Namen → **4 je Untergruppe**; geht bei 16, 26 und 64 Szenarien auf.
- Bekannte Grenze (Methodenteil): Englische Namen teilen die Sprache der Prompts und können als
  „Muttersprachler der Diskussion“ einen eigenen Effekt tragen; die Untergruppe wird je Name protokolliert und
  ist explorativ einzeln auswertbar. Handout §2 „Herkunft vierstufig“ und §3 (E±-Zusammensetzung) anpassen.

## Entscheidung 14: Namen aus Häufigkeitslisten, Engine kombiniert, keine Manipulationsprüfung (12.11. abends)

- **Bausteine:** je Gender × Untergruppe etwa 16 häufige Vornamen (Geburtskohorte etwa 1975–1995, gegen
  Alterssignale), je Untergruppe etwa 40 häufige Nachnamen; Quellen: amtliche Häufigkeitslisten (INSEE, ONS,
  Deutschland), für Nigeria/Indien/China Listen aus der Literatur, sofern veröffentlicht (noch zu prüfen).
  Vor- und Nachnamen wiederholen sich; jeder trägt eine eigene ID im Manifest.
- **Die Engine kombiniert** Vor- und Nachnamen deterministisch aus dem `master_seed` zu vollen Namen, ohne
  Wiederholung eines vollen Namens; die Zuordnung hängt nur von Szenario, Block und Pool ab (Welle 2 ändert
  Welle 1 nicht). Kein externes Namensskript.
- **Keine Manipulationsprüfung** durch das Studienmodell. Grenze im Methodenteil: Es ist nicht belegt, dass
  gpt-6-luna jeden Namen der vorgesehenen Gender-/Herkunftsgruppe zuordnet.

## Maßnahmenkatalog Code-Stabilität (Stand 15.11.)

Zusammengeführt aus der Batch-Analyse vom 11.11. (Umbauten 1–4 und Restliste) und der Kreuzprüfung vom 12.11.
(„Im Code bestätigte Befunde“). Spalte „Beleg · Begründung“ = was im Code/in den Daten gefunden wurde und warum
es für die Studie zählt.

| # | Maßnahme | Beleg · Begründung | Stand |
|---|---|---|---|
| M1 | Queue statt Shell-Spuren (`RunStudyProject`, `study:queue`, gemischte Reihenfolge) | Ein Absturz hielt eine ganze Spur an, Reihenfolge folgte der ID → Uhrzeit fiel mit Bedingungen zusammen | erledigt 11.11. |
| M2 | Infrastrukturfehler als `interrupted`, nicht `failed` (`TransientFailure`) | 09.11.: 1.625 `BroadcastException`-Turns (Reverb aus) landeten als gescheiterte Turns in den Studiendaten → verfälschte Fehlerrate | erledigt 11.11. |
| M3 | SQLite mit WAL, `busy_timeout`, `synchronous=normal` | `journal_mode` war DELETE ohne Timeout → bei vielen Workern „database is locked“ als gescheiterte Turns; Produktion später MySQL (Beschluss 29.10.) | erledigt 11.11. |
| M4 | Provenienz in `run_config`: Git-Commit + Dirty-Flag, Hashes aller Prompt-Views, `laravel/ai`-Version, Endpoint, Persona-`description` und Thema | `snapshotRunConfig` friert nur System-Prompt, Name und Attributzeilen ein → später nicht belegbar, mit welchem Code und Text ein Lauf lief | offen |
| M5 | Fehlerklassen im `ParallelPrompts`-Kindprozess angleichen | Ein 500er ist im Orchestrator `interrupted`, in Score/Blind Quality ein `TypeError` → `failed` (Statuscode geht im Kindprozess verloren) → Fehlerraten je Pipeline nicht vergleichbar, die Studie berichtet sie aber je Pipeline | offen |
| M6 | Laufübergreifender Breaker + Pausen-Flag, das jeder Job vor dem Turn prüft | 401/403/400 sind nicht transient (`TransientFailure.php:19`) → Welle in Minuten leer; leeres Guthaben (429 `insufficient_quota`) gilt als transient → nach ~2 h Backoff ebenso leer (Entscheidung 16) | offen |
| M7 | Ein Job = ein Turn, kurzer `TimeoutStopSec` | Unit `TimeoutStopSec=300` < Job 3600 s → ein Neustart killt laufende Läufe, Reservierung hängt bis `retry_after` 3900 s (Entscheidung 4) | offen |
| M8 | Worker-Zahl und Speichergrenzen an den VPS anpassen (`MemoryMax`, Swap) | Unit nennt 16 Worker, auf 4 GB passen etwa 6 → OOM-Kills mitten im Turn | offen |
| M9 | Modell-Drift hält den Lauf an statt nur zu loggen; Modell auf datierten Snapshot pinnen, falls verfügbar | `GuardAgainstModelDrift` loggt nur → ein stiller Modellwechsel mitten in der Welle landet unbemerkt in den Daten | offen |
| M10 | uuid beim Anlegen setzbar (Naht für deterministische Lauf-uuid) | `uuid` nicht fillable, `HasPublicUuid` setzt sonst einen Zufallswert → idempotentes Anlegen aus dem Laufschlüssel unmöglich | offen |
| M11 | Persona-Sperre ab erstem Einsatz (Editor, Löschen, Console, Import) | `ExpertPolicy` sperrt nur nach Admin-Rolle; `participants.csv` liest Tags/Attribute live → eine spätere Änderung verfälscht alte Läufe; bei Wiederverwendung (Entscheidung 16) zwingend | offen |
| M12 | Persona-Tag `focus persona` durch Projekt-Tag `focus seat` ersetzen (Seeder, Vorlagen, Power-Skript) | Bei wiederverwendeten Personas ist „Fokus“ Eigenschaft des Laufs, nicht der Person → Vorlagen würden falsch zählen | offen |
| M13 | Policy-Filter je Name im Probelauf messen, auffällige Namen vor Welle 1 ersetzen | 09.11.: Zelle 556 vom Filter abgebrochen („You are a woman.“) → trifft der Filter bestimmte Namen öfter, fallen Bedingungen ungleich aus | offen (Probelauf) |
| M14 | Betrieb: MySQL, Backups mit Restore-Test, `ext-zstd`, Log-Rotation, `.env.study` korrigieren | `.env.study.example` hat sqlite, `APP_DEBUG=true`, gpt-5-mini; ohne Restore-Test ist ein Backup unbewiesen | offen |
| M15 | `ParallelPrompts`-Fallback bei „Argument list too long“ beobachten | Fallback läuft sequenziell (kein Datenverlust), verlängert aber späte Turns → Laufzeitschätzung prüfen | erledigt, nur Messung |

## Entscheidung 15: Herkunftszeile nur Land (15.11.)

- Origin-Wert ist nur das Land („Nigeria“), keine Stadt. Kein Ortsfeld je Name im Manifest.

## Entscheidung 16: Nach dem Grundsatz-Brainstorming (15.11.)

- **Manifest bleibt** als Eingabe der Studie.
- **Planer als reine Funktion** `Laufschlüssel → Besetzung` (Namen, Titel, Beschreibungen, Sitze, Seed), ohne DB,
  vollständig testbar; `study:plan` und `study:check` rufen ihn nur auf.
- **Progress-Dashboard `/study` bleibt** und wird ausgebaut.
- **Personas werden über Läufe wiederverwendet** (inhaltsadressiert, gesperrt) – das ist der Sinn von Communicat;
  keine Personas pro Instanz.
- **Kein eigener Studien-User**; die Studie läuft in einer eigenen Umgebung. (Skalierung der Listen/Sidebar bleibt offen.)
- **Abarbeiten:** vorhandenes `study:queue`/`RunStudyProject`, ein Job = ein Turn, Pausen-Flag, laufübergreifender Breaker.
- **Discord-Bot statt Webhook** (ersetzt „kein Bot“ unter „Entschieden“): **melden und abfragen**, nicht steuern.
  Alarme (Fehlerbremse, Stillstand, Drift, Welle fertig), Slash-Befehl `/status` mit den Zahlen von `/study`,
  dazu ein **regelmäßiger Lagebericht** (Vorschlag: alle 6 h, Takt konfigurierbar). Pause/Resume nur über
  Leitstand oder CLI.

## Entscheidung 17: Vom Orchestrator gesetzt, damit B und D nicht blockieren (15.11.)

- **Frage 15:** Namen als Bausteine im Manifest (Vornamen je Gender × Untergruppe, Nachnamen je Untergruppe, je
  mit ID), der Planer kombiniert deterministisch aus `master_seed`, kein voller Name doppelt; optionale
  Sperrliste `excluded_full_names`. Revidierbar bis zur Präregistrierung.
- **Discord:** Meldungen und Lagebericht über die Bot-REST-API (nur Bot-Token und Kanal-ID, kein öffentlicher
  Endpunkt nötig). `/status` über einen Interactions-Endpunkt mit Signaturprüfung, nur aktiv, wenn
  `DISCORD_PUBLIC_KEY` gesetzt ist; ohne Endpunkt fällt `/status` weg, alles andere läuft.

## Teilprojekte (15.11., ersetzt die Arbeitspakete oben als Reihenfolge)

Je Teilprojekt eine eigene Spec und ein eigener Plan. Ablauf: A → B → C → Probelauf; D und F parallel, E vor dem
Probelauf.

| # | Teilprojekt | Inhalt | Spec |
|---|---|---|---|
| A | Core-Härtung | M4, M5, M9, M10, M11 | `2026-11-15-studie-core-haertung-design.md` |
| B | Planung | Manifest, Planer, Anlegen, `study:plan`, `study:check`, Projekt-Tag `focus seat` (M12) | offen; blockiert durch Frage 15 |
| C | Steuerung | ein Job pro Turn, Pausen-Flag, Breaker, Worker-Zahl (M6–M8) | offen |
| D | Überwachung | Dashboard `/study`, Discord-Bot, Stillstand | offen; Erreichbarkeit per HTTPS klären |
| E | Export/Auswertung | neue Spalten, Auswertungsskript an synthetischen Fällen | offen |
| F | Betrieb | MySQL, Backups mit Restore-Test, `.env.study`, VPS (M14) | offen |

Danach: Probelauf (48–96 Läufe, Probe-Namen, M13) → Poweranalyse neu → Präregistrierung → Chaos-Probe → Welle 1.
Offen ohne Zuordnung: Pagination von Sidebar und Listen bei 3.072 Projekten.

## Entscheidungen 1–8 (alle getroffen, siehe „Entschieden“)

Zusätzlich zu 8: **Keine Blindheitssperre.** Statistik-Flyout und `/statistics` bleiben unverändert. Jedes
Folgemanifest trägt ein Pflichtfeld `decision` (gemessene Szenario-Schwankung, angewandte Regel, Datum), das die
Engine ins Ereignisprotokoll schreibt; die Regel steht in der Präregistrierung.
