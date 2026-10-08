# Chatlänge: wie viele Turns braucht ein Lauf?

**08.10.2026** · 32 vorhandene Läufe à 60 Turns (Projekte 16–23, 25–32, 41–48, 49–56) und
6 neue Läufe à 100 Turns (Projekte 57–62) · `openai-gpt-5-mini` · Seed 42 · neue Läufe
$11,03 · 2 fehlgeschlagene Turns ohne Beitrag (beide Projekt 61, OpenAI 500 bzw.
Verbindungsabbruch, danach fortgesetzt)

Rohdaten: `2026-10-08-chatlaenge-trajektorien.csv` (Anteile nach jedem Turn, Langformat,
direkt aus `php artisan project:metrics {id} --trajectory --window=20 --format=csv`) und
`2026-10-08-chatlaenge-daten.json` (T-Werte, Sprecherfolge, Referenzsimulation je Lauf).
Auswertung und Abbildungen: `2026-10-08-chatlaenge.py` (mit `--selftest` prüft es das
Kriterium an Extremfällen). Gezählt wird nach den Regeln von `SpeakingShares`:
gesprochen = `words IS NOT NULL`, Wörter statt Tokens.

## Die neuen Läufe

Je eine Kopie der 60-Turn-Zelle „with status" (gleiche Beschreibung, gleiche Experten in
gleicher Sitzreihenfolge, gleiches Modell, Seed 42), Turn-Budget 100, Gruppe „Chatlänge".

| Projekt | Pipeline | Thema | Kopie von | Kosten |
|---|---|---|---|---|
| 57 | Score | School | 16 | $1,63 |
| 58 | Score | Office | 18 | $1,94 |
| 59 | Orchestrator | School | 25 | $0,89 |
| 60 | Orchestrator | Office | 27 | $1,02 |
| 61 | Quality | School | 41 | $2,81 |
| 62 | Quality | Office | 43 | $2,73 |

`run_config` ist bei Original und Kopie identisch, die Stage-Prompts aber nicht: Score
(26.09.) und Orchestrator (29.09.) liefen vor der nummerierten History, Quality (01.10.,
18:51) vor dem Judge mit Rollen. Original und Kopie sind deshalb **keine Replikate**; alle
Aussagen unten stützen sich auf den Verlauf innerhalb eines Laufs.

## Kriterium (vorab festgelegt)

> Ein Lauf ist **ab Turn T stabil**, wenn sich für jeden Experten der kumulative
> Turn-Anteil über die folgenden 10 Turns um höchstens 2 Prozentpunkte gegenüber seinem
> Wert in Turn T ändert und dies für jeden späteren Turn bis zum Laufende ebenso gilt.

Formal mit x_e(t) als Anteil nach t gesprochenen Turns:
`ok(s) ⇔ ∀e, j=1..10: |x_e(s+j) − x_e(s)| ≤ 0,02` und `T = min{s : ok(s') ∀ s' ∈ [s, N−10]}`.
Dasselbe für den Wortanteil und für den gleitenden Anteil (Fenster 20). Weil zehn Turns
Stabilität beobachtet sein müssen, ist T höchstens N − 10: **ein 60-Turn-Lauf kann
frühestens bei T ≤ 50, ein 100-Turn-Lauf bei T ≤ 90 als stabil gelten.** Round Robin
geht nur mit dem Wortanteil ein; der Turn-Anteil ist dort per Konstruktion 25 %.

### Warum beide Sichten

Der kumulative Anteil glättet sich **rein rechnerisch**: nach n Turns verschiebt ein
weiterer Turn ihn um höchstens etwa 1/n. Eine flache kumulative Kurve sagt also nur, dass
der Durchschnitt festliegt, nicht dass sich das Gespräch nicht mehr verändert. Das zeigt
erst der gleitende Anteil, der alles vor dem Fenster vergisst.

Um die Arithmetik vom Verhalten zu trennen, steht neben jedem T eine **stationäre
Referenz**: 1 000 simulierte Läufe, in denen jeder Turn unabhängig mit den Endanteilen
des echten Laufs vergeben wird. Das ist ein Prozess ganz ohne Dynamik. Zum Vergleich der
Extreme: strikte Rotation (Round Robin) ist nach dem Kriterium ab T = 38 stabil, ein
unabhängiger Zufallsprozess mit vier gleichen Anteilen erst im Median ab T = 166.

## Ergebnis

### 100-Turn-Läufe

| Projekt | Pipeline · Thema | T Turns kum. | T Wörter kum. | T gleitend (T/W) | Referenz T (Median / 80 %) | P(stabil in 100) Referenz |
|---|---|---|---|---|---|---|
| 57 | Score · School | **90** | 85 | – / – | 164 / 194 | 44 % |
| 58 | Score · Office | nicht stabil | 90 | – / – | 163 / 190 | 43 % |
| 59 | Orchestrator · School | **82** | 75 | – / – | 116 / 150 | 79 % |
| 60 | Orchestrator · Office | nicht stabil | 89 | – / – | 119 / 151 | 78 % |
| 61 | Quality · School | **88** | 87 | – / – | 112 / 145 | 85 % |
| 62 | Quality · Office | **70** | 74 | 78 / 78 | 86 / 119 | 92 % |

Vier von sechs Läufen erreichen das Kriterium beim Turn-Anteil, alle sechs beim
Wortanteil, fast alle aber erst kurz vor der Zensurgrenze von 90. Der gleitende Anteil
wird nur in Projekt 62 stabil, und dort nur, weil der Titelträger ab Turn 59 jeden
Turn spricht (gleitend 100 %).

Wie weit sich der Anteil des Titelträgers (Sitz 1) nach Turn 60 noch bewegt:

| Projekt | nach 60 | nach 100 | Δ | Turn-Anteil Sitz 1 in Blöcken à 20 Turns |
|---|---|---|---|---|
| 57 Score · School | 53,3 % | 49,0 % | −4,3 | 40 · 50 · 70 · 45 · 40 |
| 58 Score · Office | 51,7 % | 49,0 % | −2,7 | 65 · 40 · 50 · 55 · 35 |
| 59 Orchestrator · School | 8,3 % | 13,0 % | +4,7 | 15 · 5 · 5 · 20 · 20 |
| 60 Orchestrator · Office | 16,7 % | 22,0 % | +5,3 | 25 · 10 · 15 · 25 · 35 |
| 61 Quality · School | 75,0 % | 82,0 % | +7,0 | 65 · 75 · 85 · 95 · 90 |
| 62 Quality · Office | 81,7 % | 89,0 % | +7,3 | 70 · 85 · 90 · 100 · 100 |

Die Blöcke zeigen, was die kumulative Kurve verdeckt: **unter Quality wächst die
Dominanz des Titelträgers über den ganzen Lauf**, ein Rich-get-richer-Verlauf, der nach
60 Turns noch nicht abgeschlossen ist. Unter Score schwankt sie ohne Richtung, unter
Orchestrator steigt sie leicht. In Projekt 59 dominiert ohnehin nicht der Titelträger,
sondern Kim Hofer (81 % der Turns).

### 60-Turn-Läufe

| Pipeline | Läufe | stabil: Turn-Anteil kum. | stabil: Wortanteil kum. | gleitend | P(stabil in 60) Referenz, Median |
|---|---|---|---|---|---|
| Score (16–23) | 8 | 0 | 0 | 0 | 7 % |
| Orchestrator (25–32) | 8 | 2 (T = 35, 50) | 2 (T = 21, 50) | 0 | 21 % |
| Quality (41–48) | 8 | 2 (T = 50, 44) | 2 (T = 35, 50) | 0 | 21 % |
| Round Robin (49–56) | 8 | – | 5 (T = 40–50) | 0 | – |

Die stabilen Fälle sind Extreme: Projekt 28 (Sitz 1 spricht nie, T = 35), Projekt 43 (Sitz
1 mit 93 %, T = 44). Nach 60 Turns erfüllen also nur 4 von 24 Läufen das Kriterium beim
Turn-Anteil, und die Referenz sagt, dass auch ein Prozess ganz ohne Dynamik es bei 60
Turns meist verfehlen würde.

## Empfehlung: 100 Turns

1. **60 Turns sind zu kurz.** Der Lauf erfüllt das Kriterium nur in Extremfällen, und
   der Titelträger-Anteil bewegt sich zwischen Turn 60 und 100 noch um 3–7 Prozentpunkte,
   unter Quality systematisch nach oben. Ein 60-Turn-Lauf würde den Statuseffekt dort
   unterschätzen, und zwar je nach Pipeline unterschiedlich stark. Das ist genau die
   Wechselwirkung, die die Studie messen will.
2. **Mit 100 Turns** ist der Wortanteil in allen sechs Läufen stabil, der Turn-Anteil in
   vier, mit T zwischen 70 und 90. Die beobachteten T liegen durchweg **vor** der
   stationären Referenz (Median 86–164). Die Agenten verteilen das Wort also gleichmäßiger
   über die Zeit als ein Zufallsprozess mit denselben Endanteilen. Was nach Turn 90 noch
   schwankt, ist im Wesentlichen Arithmetik.
3. **Länger als 100 lohnt sich nicht.** Um das 2-pp-Kriterium auch für ausgeglichene
   Verteilungen zuverlässig zu erreichen, wären nach der Referenz 160–200 Turns nötig,
   bei etwa doppelten Kosten pro Lauf (100 Turns: $0,9 Orchestrator, $1,6–1,9 Score,
   $2,7–2,8 Quality). Die Unsicherheit liegt ohnehin zwischen den Läufen, nicht in ihnen:
   dieselbe Persona kommt in gleichrangigen Kontrollzellen auf 0 % bis 73 % der Turns.
   Ein zusätzlicher Lauf bringt mehr als 60 zusätzliche Turns.
4. Für die Auswertung zusätzlich den **Anteil in Zeitblöcken** (1–20, 21–40, …) als
   Robustheitsprüfung berichten. Der Prozess ist unter Quality nicht stationär, und ein
   einziger Endanteil verdeckt das.

## Grenzen

- **n ist klein:** sechs 100-Turn-Läufe, nur „with status", nur School und Office, je
  Zelle ein Lauf. T ist eine Einzelbeobachtung, kein geschätzter Parameter.
- **Ein Modell** (gpt-5-mini). Andere Modellfamilien können anders schnell einrasten.
- **Kumulative Glättung:** ein stabiles T für den kumulativen Anteil beweist keinen
  stationären Prozess (siehe Blöcke). Das 2-pp-Kriterium auf dem gleitenden Anteil ist
  praktisch unerreichbar, weil ein Turn ihn bei Fenster 20 um 5 pp verschiebt. Es
  bestätigt hier nur die Monopolisierung in Projekt 62.
- **Rechtszensur:** T ≤ N − 10. Ein „stabil ab 90" in einem 100-Turn-Lauf heißt auch:
  nicht früher belegbar. T hängt von N ab und ist zwischen 60- und 100-Turn-Läufen nicht
  direkt vergleichbar.
- **Keine Replikate:** zwischen den 60-Turn-Originalen und den 100-Turn-Kopien haben sich
  Stage-Prompts geändert (siehe oben). Die Endanteile der Paare (z. B. 16: 63 % gegen
  57: 49 %) taugen deshalb nicht als Maß für die Streuung bei Wiederholung.

## Abbildungen

Je Lauf links kumulativer, rechts gleitender Turn-Anteil (Fenster 20), Titelträger blau,
Stabilitätspunkt T gestrichelt, Zufallserwartung 25 % gepunktet:
`2026-10-08-chatlaenge-abb-{57-score-school, 58-score-office, 59-orchestrator-school,
60-orchestrator-office, 61-quality-school, 62-quality-office}.{svg,png}`.
