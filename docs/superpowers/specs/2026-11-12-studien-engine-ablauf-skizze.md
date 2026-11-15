# Studien-Engine: Wie Testfälle erzeugt und abgearbeitet werden (Skizze, 12.11.2026)

Eigenständige Skizze zum Gegenlesen. Hintergrund und Begründungen: `2026-11-12-studien-engine-brainstorming-notizen.md`,
Studiendesign: Handout „2026-11-16_Handout-Studiendesign.pdf“ (§3 Matrix, §6 Laufplan). Noch kein Code.

## 1. Ausgangspunkt: die Matrix

- Dreiergruppe: **Fokus-Persona** + **Duo** (zwei Mitdiskutierende mit gleichem Profil).
- Profil = Status (S+ hoher / S− niedriger Titel) × Gender (G+ Mann / G− Frau) × Herkunft (E+ europäisch /
  E− nicht-europäisch) → 8 Profile.
- Matrix = 8 Fokusprofile × 8 Duoprofile = **64 Zellen**. Drei Pipelines (Score, Orchestrator, Blind Quality).
- Je Zelle und Pipeline läuft jedes Szenario genau einmal. Zielgröße: Turn-Anteil der Fokus-Persona in 30 Turns.
- Welle 1 = 16 Szenarien → 16 × 64 × 3 = **3.072 Läufe**. Spätere Wellen fügen Szenarien hinzu (17–32, 33–64).

## 2. Eingabe: eine feste Studiendatei (Manifest)

Einmal erstellt, danach nie geändert (Hash). Inhalt:

- Einstellungen: Modell (`openai-gpt-6-luna`), 30 Turns, Pipelines, `master_seed`.
- **Namen:** 240 Einträge, 4 Pools à 60 (Mann/Frau × europäisch/nicht-europäisch). Je Eintrag: ID (kodiert
  Pool, z. B. `M-EU-001`), Name, Untergruppe (z. B. german, nigerian, indian, chinese), Herkunftsort.
  Nicht-europäisch und europäisch sind je gemischt aus mehreren Untergruppen zu gleichen Teilen.
- **Szenarien:** alle 64 von Anfang an. Je Szenario: Thema (Entscheidungsfrage), Titelpaar (`high`, `low`,
  z. B. Principal / Student) und ein **festes Triple aus drei Beschreibungen A, B, C** im Muster
  „You know … / You do not know …“.

Beim Hochladen einer Welle kommen nur hinzu: Szenariobereich (z. B. 1–16) und `decision` (Begründung der
Aufstockung, ab Welle 2 Pflicht). Ab Welle 2 muss der Hash der Datei identisch sein.

## 3. Wie aus der Matrix Läufe werden

**Blöcke.** Die 64 Zellen zerfallen in **16 Blöcke**: Fokus (Gender × Herkunft) × Duo (Gender × Herkunft).
Jeder Block hat 4 Zellen, die sich nur im Status unterscheiden:

|               | Duo S+ | Duo S− |
|---------------|--------|--------|
| **Fokus S+**  | Zelle  | Zelle  |
| **Fokus S−**  | Zelle  | Zelle  |

**Instanz** = Szenario × Block. Welle 1: 16 Szenarien × 16 Blöcke = 256 Instanzen.
Je Instanz: 4 Statuskombinationen × 3 Pipelines = **12 Läufe** (256 × 12 = 3.072).

**Was innerhalb einer Instanz für alle 12 Läufe gleich ist:**

- die drei **Namen**: Fokus aus dem Pool seines Gender × Herkunft, Duo zwei Namen aus dem Pool des Duos;
  kein Name doppelt in der Instanz;
- die **Beschreibungen**: Fokus immer A, Duo B und C (in allen 64 Zellen eines Szenarios).

**Was sich innerhalb der Instanz ändert:** die **Titel** (S+ → `high`, S− → `low`, je für Fokus und Duo),
die **Sitze** (vor jedem Lauf zufällig aus dem Seed verteilt, unabhängig je Lauf, auch zwischen Pipelines;
Fokus-Sitz als fester Faktor im Modell) und der **Seed** (`hash(master_seed, Instanz, Statuskombination,
Pipeline)`).

Dadurch ist der Statusvergleich (H1.1, H2.1) ein Vorher-Nachher-Vergleich derselben Personen mit gleichem
Hintergrund. Gender und Herkunft werden zwischen Blöcken verglichen (andere Namen), der
Hintergrund der Fokus-Persona bleibt dabei innerhalb eines Szenarios trotzdem gleich (A).

**Namensziehung.** Jeder Pool wird einmal mit dem `master_seed` gemischt (Untergruppen abwechselnd) und der
Reihe nach abgearbeitet. Die Position hängt nur von Szenarionummer und Block ab, nicht von der Wellengröße →
Welle 2 verändert keine Zuordnung aus Welle 1. Je Szenario braucht jeder Pool 12 Namen (4 Fokus + 8 Duo);
Welle 1 ≈ 3 Einsätze je Name, 64 Szenarien ≈ 13.

**Datensätze.**

- Persona = Beschreibung + Name + Titel + Herkunft → normaler Communicat-Experte, find-or-create über eine
  aus dem Inhalt abgeleitete uuid, nach erstem Einsatz gesperrt. Tags `study: main`, `pool entry: M-EU-001`,
  Untergruppe. Attribute Role (Titel), Gender, Origin, Sichtbarkeit `all`.
- Lauf = normales Communicat-Projekt, uuid aus dem Laufschlüssel (Szenario, Block, Statuskombination,
  Pipeline, Versuch). Thema = Szenariothema. Tags: `wave`, `scenario`, `instance`, `focus profile`,
  `duo profile`, `focus status`, `duo status`, `focus seat`.
- Wellenzustand in der Modul-Tabelle `study_waves`, Ereignisse in `study_events`. Keine eigene Lauftabelle.

## 4. Ablauf einer Welle

1. **Upload** (Leitstand oder `study:plan`): Datei + Szenariobereich + `decision`.
2. **Prüfung und Vorschau** (`--dry-run`): Schema, IDs, Hash gegen frühere Wellen, Laufzahl, neue Personas,
   Kostenschätzung, Balance-Tabelle (Sitze, Untergruppen, Namenshäufigkeit), je Szenario ein gerenderter Prompt.
3. **Bestätigen → Anlegen:** Personas und Projekte in Chunks, idempotent (zweimal anlegen = gleiches
   Ergebnis). Welle steht auf `geplant`.
4. **Preflight:** ein echter API-Aufruf, Key, Plattenplatz, aktuelles Backup → Welle `läuft`.
5. **Einreihen:** alle Läufe in gemischter Reihenfolge (`--order-seed`, protokolliert), damit Zellen nicht
   mit der Zeit zusammenfallen.
6. **Abarbeiten:** ein Job = **ein Turn**. Der Job führt einen Turn über den unveränderten `TurnRunner` aus
   und reiht den Lauf danach neu ein, bis 30 gesprochene Turns erreicht sind (gescheiterte Turns zählen nicht).
   Eine Sperre je Projekt verhindert parallele Turns desselben Laufs. Etwa 6 Worker auf dem VPS.
7. **Störungen:** vorübergehende Fehler (Netz, 429, 5xx) → Turn `interrupted`, Backoff, Lauf geht weiter.
   Ein Lauf, der eine Grenze gescheiterter Turns überschreitet, wird aufgegeben (`superseded`) und als neuer
   Versuch mit gleicher Besetzung, neuem Seed angelegt.
8. **Überwachung** (`study:watch`, jede Minute): Fehlerbremse über alle Läufe (z. B. 401/402 → Welle pausieren),
   Kostenbremse gegen Budget, Modell-Drift, Stillstand, Platte, Worker-Herzschlag. Alarme per Mail und Discord,
   externer Dead-Man's-Switch. Pause ist ein Flag, das jeder Job vor dem Turn prüft.
9. **Ende:** alle Läufe mit 30 gesprochenen Turns → `study:check` rechnet den Plan neu und vergleicht jeden
   Lauf (Besetzung, Sitze, Seeds, Tags, Commit) → Prüfbericht per Mail → Welle `fertig` → Export und Backup.
10. **Nächste Welle:** gleiche Datei, nächster Szenariobereich, `decision` ausgefüllt. Die Entscheidung trifft
    der Forscher, nicht die Engine.

## 5. Offen / zu prüfen

- Sitze sind rein zufällig je Lauf (Entscheidung des Forschers); die Vorschau zeigt die erreichte Verteilung je Zelle.
- Worker-Zahl, Rate-Limits von gpt-6-luna, Kosten je Lauf: im Lasttest bzw. Probelauf messen.
- Probelauf mit eigenen Probe-Namen und Probe-Szenarien, damit keine Hauptstudien-Personas gesperrt werden.
