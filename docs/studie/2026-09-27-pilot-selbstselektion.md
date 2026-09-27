# Pilot: Statuseffekt unter Selbstselektion

**27.09.2026** · 8 Zellen · ScorePipeline · `openai-gpt-5-mini` · seed 42 · je 60 gesprochene Turns · 0 Fehlschläge · $7,93

Rohdaten: `2026-09-27-pilot-selbstselektion-daten.json` (Projekte 16–23 in der lokalen SQLite).

## Aufbau

Vier Szenarien, je ein Paar aus Status- und Kontrollbedingung. Zwischen den beiden
Läufen eines Paares unterscheidet sich **genau eine Zeichenkette**: das Rollenetikett
einer Persona. Beschreibung, Wissensprofil, Sitzordnung, Seed, Thema, Pipeline und
Modell sind identisch, weil jede Persona doppelt angelegt ist — einmal unter dem
Status- und einmal unter dem Gleichstellungslabel, beide aus derselben Quellzeile in
`database/experts.json`.

| Szenario | Thema | Titel vs. Gleichstand |
|---|---|---|
| Schule | Should phones be banned in school from year 5 onwards? | Teacher / Student |
| Family | Should the voting age be lowered to 16? | Parent / Child |
| Club | Should we rest our key players in the next match? | Coach / Player |
| Büro | Should the company require fixed days in the office, and how many? | Manager + Intern / Employee |

Büro ist das einzige gestufte Szenario: ein Manager, zwei Employees, ein Intern. Die
anderen drei sind flach — ein Titel, drei Gleichrangige.

## Ergebnis

Turnanteil derselben Persona, mit und ohne Titel. Zufallserwartung bei vier
Teilnehmern: 25,0 %.

| Szenario | Persona | Titel | ohne Titel | mit Titel | Δ |
|---|---|---|---|---|---|
| Schule | Alex Brandt | Teacher | 16,7 % | 63,3 % | +46,6 |
| Family | Mika Sommer | Parent | 25,0 % | 61,7 % | +36,7 |
| Club | Nikita Falk | Coach | 28,3 % | 55,0 % | +26,7 |
| Büro | Sam Delius | Manager | 30,0 % | 46,7 % | +16,7 |
| **Mittel** | | | **25,0 %** | **56,7 %** | **+31,7** |

Vier von vier in derselben Richtung.

**Der Mittelwert ohne Titel liegt bei exakt 25,0 %** — der Zufallserwartung. Das
entkräftet den naheliegendsten Einwand: die später betitelte Persona ist nicht
ohnehin die dominante. Einzeln streut sie allerdings zwischen 16,7 % und 30,0 %.

### Wortanteil gegen Turnanteil

| Szenario | Turns | Wörter | Differenz |
|---|---|---|---|
| Club | 55,0 % | 64,9 % | +9,9 |
| Schule | 63,3 % | 72,2 % | +8,9 |
| Büro | 46,7 % | 50,9 % | +4,2 |
| Family | 61,7 % | 61,7 % | 0,0 |

In drei von vier Fällen redet der Titelträger nicht nur öfter, sondern pro Beitrag
auch länger — die Richtung von Deng2025. Die beiden Maße laufen auseinander; sie
getrennt zu führen ist gerechtfertigt.

### Gini (Turns), Decke 0,750

| Szenario | mit Status | gleichgestellt |
|---|---|---|
| Schule | 0,408 | 0,175 |
| Büro | 0,400 | 0,092 |
| Family | 0,500 | 0,308 |
| Club | 0,350 | **0,392** |

## Die zentrale Einschränkung: die Kontrollen sind nicht gleichverteilt

Im Club-Kontrolllauf ist die Ungleichheit **höher** als im Statuslauf: dort nahm sich
Sam Peters ohne jeden Titel 53,3 % des Rederechts. In der Family-Kontrolle kam Sascha
Sommer auf 43,3 %. Im Büro liegen Noor Kessler (3,3 %) und Robin Aalto (40,0 %)
um den Faktor zwölf auseinander, obwohl beide in **beiden** Bedingungen schlicht
`Employee` sind.

Die präzise Aussage ist daher nicht „Status erzeugt Ungleichheit", sondern:

> Ein Agent reißt das Gespräch ohnehin an sich. Der Titel bestimmt zuverlässig, welcher.

Das Rauschen zwischen nominell gleichrangigen Agenten hat dieselbe Größenordnung wie
der Statuseffekt selbst.

## Was der Pilot nicht trägt

- **n = 1 pro Zelle.** Vier gepaarte Beobachtungen. Vorzeichentest: 4 von 4 ergibt
  einseitig p = 0,0625. Mit vier Paaren ist die konventionelle Schwelle selbst bei
  perfektem Ergebnis unerreichbar.
- Eine Pipeline (Score), ein Modell, ein Seed, ein Thema je Szenario.
- **Der Titel ist nie über die Sitze rotiert.** Er saß immer auf Sitz 1. Titeleffekt
  und Profileffekt sind damit nicht getrennt — nur im Mittel über die vier Szenarien
  fällt die Basislinie auf 25,0 %.
- Beiträge sind im Schnitt 200–400 Wörter lang, mit Aufzählungen und
  Maßnahmenkatalogen. Ein Turn ist damit eher ein Memo als ein Gesprächsbeitrag; der
  menschliche Statuseffekt auf den Redeanteil ist an Gesprächen gemessen. Gehört in
  die Limitationen. Ursache: der Speak-Prompt hat bewusst keine Längenvorgabe mehr,
  weil eine Deckelung den Wortanteil an den Turnanteil heranzöge und damit die
  Divergenz der beiden Maße wegdrückt.

## Nächster Schritt

Rotation des Titels über alle vier Sitze, je Szenario und Bedingung. Das beseitigt
den einzigen echten Konfundierer der jetzigen Zahlen und kostet bei $0,017 pro Turn
rund $32 — vor der Ausweitung auf Anthropic und Gemini.

## Verhältnis zur Vorarbeit

**Tak2026** fand unter Priority Bidding für ein „instructor"-Label 14,5 % statt der
erwarteten 33 % — die Gegenrichtung. Deren Scheduler balanciert Teilnahme per
Default; `HighestBidSelector` enthält keinerlei Ausgleichslogik. Genau das war die
Vermutung, und der Pilot ist mit ihr vereinbar.

## Zwei Defekte, die der Pilot aufgedeckt hat

Beide behoben, beide haben vorherige Läufe unbrauchbar gemacht:

1. **Der Nutzer stand in der Teilnehmerliste** (`- admin [U1] (user)`), in jedem
   Prompt jedes Laufs. Ein Agent hat ihn angesprochen: „Admin, can we promise
   hall-duty in the heavy corridors …". Eine fünfte, ungemessene Person in einer
   Gruppe von vier.
2. **Der Projekttitel stand im Prompt** — also `Title: School self-selection with
   status`. Der Agent las damit seine eigene Versuchsbedingung.

Dazu eine Infrastrukturgrenze: `ParallelPrompts` übergab die serialisierte Closure
als Kommandozeilenargument; ab Turn 39 einer Zelle scheiterte jeder Turn mit
`posix_spawn(): Argument list too long`. Fällt jetzt auf sequentielle Ausführung
zurück.
