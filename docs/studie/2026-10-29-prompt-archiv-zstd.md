# Prompt-Archiv: Entscheidung für zstd

**29.10.2026** · Messung an einer Kopie der Datenbank (107 Projekte, 33.257 Prompts) ·
zstd über PHP `ext-zstd` 0.15.2 mit libzstd 1.5.7 · Vergleichsverfahren gzip, bz2, xz,
Huffman

## Stand und Kontext

Dieses Dokument ist nach dem letzten Meeting mit dem Professor entstanden (Meeting: 20.10.2026; das
Dokument selbst: 29.10.2026). Beschluss laut Nutzer aus diesem Meeting: Quality blind wird weiterverfolgt,
Quality (nicht blind) wird gestrichen. Das Prompt-Archiv ist davon nicht betroffen, es ist
pipelineunabhängig.

Rohdaten und Skripte (Aufruf steht jeweils in den ersten Zeilen):

- `2026-10-29-prompt-archiv-zstd.php`, Ergebnis `2026-10-29-prompt-archiv-zstd.json`
  (zstd Level 1/3/10/15/19/22, gzip 9, xz 6, je Projekt Größe und Zeiten)
- `2026-10-29-prompt-archiv-kompression.py`, Ergebnis `2026-10-29-prompt-archiv-kompression.json`
  (Huffman, gzip pro Zeile und pro Projekt, bz2, xz)

Beide Skripte lesen eine **Kopie** der Datenbank, nie die echte. Das PHP-Skript erwartet
die Kopie unter `<Ordner über dem Skriptordner>/db/copy.sqlite` (Pfad im Skript, kein
Argument); das Python-Skript nimmt sie als erstes Argument.

## Ausgangslage

`prompts:prune` löschte Prompt-Texte nach `PROMPT_RETENTION_HOURS` (Standard 24) und
ersetzte sie durch einen Vermerk, weil die Texte 95 % der Datenbank ausmachten (709 von
815 MB am 13.10.2026). Das Betreuer-Anliegen ist, dass sich Prompts nachträglich
nachvollziehen lassen; beim Test fiel auf, dass die Historie fehlte. Die Idee des
Professors war Huffman-Kodierung.

Begriff: Das „Long-Term-Memory“ im Sinne dieses Dokuments ist ein **Prompt-Archiv**, nicht
`projects.long_term_memory` (die laufende Chat-Zusammenfassung der Agenten).

## Entscheidung

Prompts, die älter als die Aufbewahrungsfrist sind, werden **pro Lauf von `prompts:prune` und Projekt** (alle bis dahin fälligen Prompts des
Projekts) als JSON
`{prompt_log id: Text}` mit **zstd Level 19** komprimiert und in `prompt_archives`
abgelegt. `prompt_logs.prompt` wird danach leer, `prompt_logs.prompt_archive_id` verweist
auf das Archiv. Gelesen wird transparent über `PromptLog::prompt_text` (Debug-Panel,
Bundle-Export). Archiviert wird nur `prompt`; `response`, `reasoning`, Tokens, Status und
Fehler bleiben in der Tabelle. Calls ohne Projekt bekommen weiter den alten Vermerk.

Gespeichert werden die komprimierten Bytes: `payload` ist eine Binärspalte, unter MySQL und
MariaDB `LONGBLOB`, unter SQLite ein unbegrenzter BLOB. Base64 kommt in diesem Dokument nur
als Vergleichsgröße vor.

## Messgrundlage

**Daten.** Kopie der Datenbank, 107 Projekte, 33.257 Prompts, 1.024,9 MB JSON-Payload,
Zeitraum 19.10.2026 15:04 bis 20.10.2026 14:59. Ein Projekt hat im Mittel etwa 10 MB, das
größte 18,5 MB. Ein Prompt ist im Mittel 32 KB lang, der Median liegt bei 32 KB, das
P90 bei 55 KB.

**Methode.** Je Projekt werden alle Prompts (ohne den Vermerk und ohne Calls ohne
Projekt) als ein JSON-Objekt `{id: Text}` gebaut, das der Form der späteren Archiv-Nutzlast
entspricht, und dann komprimiert. Ersparnis ist `1 − komprimiert / roh`, summiert über alle
Projekte. Gemessen werden Größe, Packzeit und Entpackzeit; jede Entpackung wird gegen das
Original verglichen. Pro Verfahren lief ein eigener PHP-Prozess, damit der Spitzenspeicher
dem Verfahren zugeordnet ist. Die Entpackzeit je Projekt ist das Minimum aus drei Läufen.

### a) Verfahren im Vergleich (Python-Messung)

| Verfahren | Ersparnis |
|---|---|
| Huffman Order-0, pro Zeile (Codetabelle nicht mitgezählt) | 43,9 % (Entropiegrenze 44,2 %) |
| gzip pro Zeile, Level 6 / 9 | 73,5 % / 73,6 % |
| gzip pro Projekt, Level 6 / 9 | 81,6 % / 83,5 % |
| gzip pro Projekt nach Base64, Level 6 / 9 | 75,4 % / 78,0 % |
| bz2 pro Projekt | 97,1 % |
| xz pro Projekt | 99,4 % |

### b) zstd gegen gzip und xz (PHP-Messung, Summe über 107 Projekte, ohne Base64)

| Verfahren | Größe | Ersparnis | Packen | Entpacken |
|---|---|---|---|---|
| zstd Level 1 | 20,49 MB | 98,00 % | 0,3 s | 0,21 s |
| zstd Level 3 | 11,63 MB | 98,87 % | 0,3 s | 0,20 s |
| zstd Level 10 | 8,02 MB | 99,22 % | 1,1 s | 0,19 s |
| zstd Level 15 | 6,95 MB | 99,32 % | 109,7 s | 0,19 s |
| **zstd Level 19** | **6,97 MB** | **99,32 %** | **14,3 s** | **0,20 s** |
| gzip 9 | 172,46 MB | 83,17 % | 29,9 s | 1,62 s |
| xz 6 | 6,46 MB | 99,37 % | 91,4 s | 0,94 s |

Level 22 wurde nur an 4 Projekten gemessen (63,2 MB roh): gleiche Größe wie Level 19
(0,42 MB), Packzeit 1,9 s gegen 0,8 s.

### c) Entpackzeit pro Projekt (Minimum aus 3 Läufen)

| Verfahren | Median | P90 | Maximum |
|---|---|---|---|
| zstd (Level 1–19) | 1,7–1,9 ms | 2,8–3,2 ms | 3,7–4,9 ms |
| gzip 9 | 13,4 ms | 28,0 ms | 34,8 ms |
| xz 6 | 8,4 ms | 12,2 ms | 15,6 ms |

### d) Größtes Projekt (18,5 MB roh)

| Verfahren | Größe | Packen | Entpacken |
|---|---|---|---|
| zstd 3 | 120 KB | | |
| zstd 10 | 77 KB | 15 ms | |
| zstd 19 | 63,5 KB | 188 ms | 3,7 ms |
| gzip 9 | 3,34 MB | 526 ms | 30,5 ms |
| xz 6 | 59,7 KB | 2287 ms | 15,6 ms |

PHP-Spitzenspeicher des Messskripts (inklusive Aufbau des JSON): zstd 78 MB, gzip 63 MB,
xz 50 MB. Der native Speicher von zstd wurde nicht gesondert gemessen.

### e) Eigenschaften der Erweiterung

`zstd_compress(string $data, int $level = 3, ?string $dict = null)`: Level 1–22, ein
Wörterbuch ist möglich, Fenstergröße und Long-Mode sind nicht einstellbar. Der Roundtrip
über alle 107 Projekte war byte-gleich (0 Abweichungen).

## Begründung

1. **Huffman allein genügt nicht.** Es bringt etwa 44 % und bleibt an der Entropiegrenze
   (44,2 %). Die Wiederholung zwischen den Prompts eines Laufs (Systemprompt, Personas,
   History) nutzt nur ein Verfahren mit Wörterbuch oder Fenster. Deflate (gzip) besteht aus
   LZ77 plus Huffman, kommt aber pro Projekt nur auf 83 % (Level 9). *Vermutung, nicht
   isoliert geprüft:* Das 32-KB-Fenster von gzip ist der Grund, denn die Prompts eines
   Projekts sind im Mittel 32 KB lang und wiederholen sich über mehrere Prompts hinweg.
2. **zstd 19 gegen gzip 9 und xz 6.** Rund 25-mal kleiner als gzip 9 (6,97 gegen
   172,46 MB). Gegenüber xz 6 nur 8 % größer (6,97 gegen 6,46 MB), aber 6-mal schneller beim
   Packen (14,3 gegen 91,4 s) und 5-mal schneller beim Entpacken (0,20 gegen 0,94 s).
3. **Level 15 und 22 bringen nichts.** Level 15 ist mit 6,95 MB praktisch gleich groß wie
   Level 19 (6,97 MB), aber deutlich langsamer beim Packen (109,7 gegen 14,3 s, rund 8-mal). Die Ursache wurde nicht untersucht; vermutlich wechselt
   libzstd dort die Strategie (Vermutung). Level 22 ergab an vier Projekten dieselbe Größe
   wie 19.
4. **Die Entpackzeit hängt nicht vom Level ab** (0,19 bis 0,21 s für alle 107 Projekte).
   Das Debug-Panel zahlt für das hohe Level nichts; nur das stündliche Archivieren braucht
   länger.
5. **Ein Archiv pro Lauf und Projekt statt pro Zeile.** Pro Zeile spart gzip nur 73,6 %, pro
   Projekt 83,5 %; nur ein Archiv über mehrere Prompts eines Projekts nutzt die Wiederholung
   zwischen den Turns.

## Folgen und Grenzen

- Jeder Rechner, der archiviert oder liest, braucht `ext-zstd`. Lokal ist sie unter Herd
  Lite vorhanden; die Produktion wurde nicht geprüft. `prompts:prune` ist so vorgesehen,
  dass es ohne die Erweiterung vor dem ersten Schreiben abbricht.
- Prompts, die schon vor Einführung des Archivs gelöscht wurden (Vermerk), sind nicht
  wiederherstellbar.
- Die Spalte `payload` ist unter MySQL/MariaDB ein `LONGBLOB` (die Migration weitet das
  64-KB-`BLOB` von `binary()` auf). Die Produktion läuft auf MySQL, lokal und in den Tests
  läuft SQLite; Migration und `PrunePromptsTest` wurden einmal gegen eine Wegwerf-MariaDB 13.0
  geprüft, gegen MySQL selbst nicht.
- Die Daten stammen aus zwei Tagen mit Läufen von bis zu etwa 100 Turns. Andere Prompt-Formen
  können abweichen.
- Nicht gemessen: kalter Erstlauf beim Entpacken, CPU des Zielservers, nativer Speicher
  von zstd.
- Die gemessenen 99,3 % gelten für ein Archiv, das ein ganzes Projekt auf einmal enthält.
  Bei stündlichen Läufen laufender Studien entstehen mehrere kleine Archive pro Projekt, ein
  Archiv pro Lauf und Projekt. Die Ersparnis ist dort vermutlich geringer als hier, weil
  weniger Wiederholung im Archiv steckt (Vermutung, nicht gemessen).
- Ohne `ext-zstd`: `prompts:prune` bricht vor dem ersten Schreiben ab und lässt alle Texte im
  Klartext, bis die Erweiterung vorhanden ist; die Aufbewahrungsfrist greift dann bewusst
  nicht, damit nichts verloren geht. Das Lesen im Debug-Panel liefert keinen Text und einen
  eigenen Hinweis. Der Bundle-Export bricht vorgesehen mit einer Fehlermeldung ab (wird gerade
  umgesetzt).
- Packen ersetzt ungültige UTF-8-Bytes durch U+FFFD (`JSON_INVALID_UTF8_SUBSTITUTE`); in
  diesem Randfall ist das Archiv nicht byte-identisch zum Original.
- Das Archiv hat anders als der frühere Vermerk kein Verfallsdatum: Die Texte bleiben lesbar,
  bis das Projekt gelöscht wird (Cascade). Der Projekt-Export (`can:view,project`) kann
  dadurch auch früher gelöschte Prompts enthalten.
- Erster Lauf nach einem großen Altbestand: Das Packen eines Projekts hielt im Messskript bis
  zu 78 MB PHP-Speicher (bei 18,5 MB Rohdaten). Bei `memory_limit` 128M ist das für große
  Projekte knapp (vermutet, nicht gemessen).
