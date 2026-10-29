# Absicherungsanalyse zum Beschluss Turn-Budget 30

## Einordnung

Der Beschluss, die Läufe der Studie auf 30 Turns je Lauf zu begrenzen (aus dem Quellenarchiv der
Masterarbeit begründet), ist gesetzt; er steht in
[`docs/reports/2026-10-29-beschluss-turn-budget-30.md`](../reports/2026-10-29-beschluss-turn-budget-30.md).
Dieser Bericht liefert die dort unter „Offen / Vorschlag zur Absicherung" vorgesehene Rechnung: was die
vorhandenen Läufe bei Turn 30 gegen Turn 60 (und, wo vorhanden, 100) zeigen. Er prüft den Beschluss nicht
und ersetzt dessen Begründung nicht.

**29.10.2026** · Datenstand der Datenbank: 20.10.2026 (letzter gesprochener Turn 14:58) · 258 Läufe
ausgewertet, 49 weggelassen · Auswertung auf einer Kopie der Datenbank, die echte wurde nicht geöffnet.

Rohzahlen: `2026-10-29-chatlaenge-30-turns.json` (je Lauf und Fenster, alle Kontraste, Stabilität,
Zeitblöcke, Power). Neu erzeugen: `2026-10-29-chatlaenge-30-turns.py` (Aufruf im Kopf der Datei;
`--verify` vergleicht jeden Lauf mit `project:metrics --trajectory`). Das Vorbild, der Bericht vom
08.10. (`2026-10-08-chatlaenge.md`, Skript `.py`), liegt im Arbeitsverzeichnis nicht mehr vor, nur noch
in der Git-Historie (`git show HEAD:docs/studie/2026-10-08-chatlaenge.md`); Kriterium und Funktionen
wurden von dort unverändert übernommen.

## Stand und Kontext

Auswertung vom 29.10.2026, Datenstand der Datenbank 20.10.2026. Der Bericht ist nach dem Meeting mit dem
Professor am 20.10.2026 entstanden.

## Davon unabhängig: Pipeline-Auswahl (Beschluss 20.10.2026)

Beschluss laut Nutzer: Quality blind wird weiterverfolgt (neben Score und Orchestrator), Quality (nicht
blind) wird nicht weiterverfolgt. Begründung laut Nutzer: Quality sei im Ergebnis nicht blind, das werde im
Team eher als Fehler gewertet. Im Code sieht der Quality-Judge die Rollen der Teilnehmenden absichtlich
(damit ein Titel erkennbar ist), Quality blind trennt diese Wirkung. Quality-Läufe stecken weiter in den
Daten und stehen deshalb in den Tabellen; die Beschlusslage betrifft die weitere Untersuchung, nicht diese
Auswertung.

## Zusammenfassung

Dieser Bericht beschreibt, was die vorhandenen Läufe zeigen, wenn nur die ersten 30 gesprochenen Turns
jedes Chats betrachtet werden, gegenüber Turn 60 (und, wo vorhanden, 100).

**Bei Turn 30 gilt gegenüber Turn 60:**

1. **Richtung und Größenordnung des Titeleffekts bleiben bei 30 Turns erhalten.** Über die drei
   Modellfamilien gepoolt (je 12 Paare mit Titel/Kontrolle, gepaart über das Thema) liegt der Effekt auf
   den Turn-Anteil der Fokus-Persona bei 30 Turns, bei 60 Turns: Quality +27,8 / +27,2 Prozentpunkte (pp),
   Quality blind +27,8 / +29,7, Orchestrator +17,2 / +20,4, Score +11,9 / +14,0, Round Robin 0 / 0 (per
   Konstruktion). Das Vorzeichen ist in mindestens 11 von 12 Paaren bei allen vier Mechanismen positiv, bei 30 wie bei
   60. Die Rangfolge der Pipelines bleibt gleich bis auf einen Tausch der beiden Quality-Varianten, die bei
   30 Turns gleichauf liegen (beide +27,8).
2. **Die Streuung zwischen den Läufen ist bei 30 Turns nicht größer als bei 60** (Median des
   SD-Verhältnisses 30/60: 1,0), die Zahl der nötigen Läufe also nach grober Schätzung ähnlich. Sie ist in
   beiden Fenstern groß, weil sie von Thema und Modell dominiert wird, nicht von den Turns.

**Befund je Mechanismus:**

- Quality, Quality blind, Orchestrator: Die Richtung des Statuseffekts ist bei Turn 30 und bei Turn 60
  dieselbe. Zur Größe des Effekts und zu den Unterschieden zwischen den Pipelines liegen Befunde nur unter
  Vorbehalt vor (siehe „Einschränkungen und Abweichungen").
- Score und Jev Score: Der Effekt hängt von der Länge ab (siehe „Einschränkungen und Abweichungen").

**Einschränkungen und Abweichungen:**

1. **Nach dem vorab festgelegten Einpendel-Kriterium erfüllt 30 Turns es nicht, 60 aber ebenso kaum.**
   Bei 30 Turns erfüllt es 1 von 60 Orchestrator-Läufen und kein einziger Lauf der anderen Pipelines;
   bei 60 sind es Orchestrator 16 von 60, Quality 9 von 60, Score 0 von 44. Ein Prozess ganz ohne Dynamik
   erfüllt es ähnlich selten (Referenz 0 bis 5 % bei 30, 4 bis 22 % bei 60). Das Kriterium trennt hier
   also 30 nicht scharf von 60.
2. **Die Effektgröße ist bei 30 Turns nur für manche Serien belastbar.** Quality (Pos. Status mit
   gpt-5-mini, Luna), Quality blind (gpt-5-mini), Orchestrator (Luna) und Jev Quality zeigen schon bei 30 Turns
   einen Effekt, dessen Konfidenzintervall die Null ausschließt. **Score ist die instabile Pipeline:** der
   Effekt wächst mit gpt-5-mini von +22,5 auf +31,7 pp, schrumpft mit Luna von +10,0 auf +4,2 und
   verschwindet bei Jev Score (+6,7 bei 30, −0,4 bei 60). Wer Score bei 30 Turns misst, misst in
   Luna-Serien einen anderen Effekt als bei 60.
3. **Was 30 Turns nicht abbilden:** Unter Quality wächst der Anteil des Titelträgers nach Turn 30 weiter
   (diese Aussage beruht allein auf Quality, für Quality blind gibt es keine 100-Turn-Läufe).
   In den beiden einzigen Läufen mit 100 Turns (Chat length, ohne Kontrolle) steigt er von 70 auf 82 % und
   von 73 auf 89 %. Die 30-Turn-Messung bildet das Plateau nicht ab; ob der Effekt in Prozentpunkten über
   die Kontrolle bei 100 Turns größer ist als bei 30, lässt sich ohne 100-Turn-Kontrollen nicht prüfen.

**Nicht prüfbar, weil …**

- der Effekt bei 100 Turns: es gibt nur sechs 100-Turn-Läufe, alle ohne Kontrollzelle; für Quality blind
  gibt es keine.
- die Rolle von Thema, Modell und Lauf als Zufallseffekte: gemischte Modelle sind in diesem Bericht nicht
  gerechnet, die Intervalle beruhen auf Paaren über das Thema.

## Datenbasis

- 307 Läufe in der Kopie. **258 haben mindestens 30 gesprochene Turns und sind ausgewertet.** **49 fallen
  weg:** 48 Läufe der Gruppe „Origin" (angelegt, noch nicht gelaufen, 0 gesprochene Turns) und 1 Testlauf
  mit gpt-5-nano und 4 Turns. Kein Lauf fällt weg, weil er zwischen 5 und 29 Turns hätte.
- Gezählt wird wie in `SpokenTurns`: gesprochen = `words IS NOT NULL`, Wörter statt Tokens, alle
  Teilnehmenden zählen, auch mit 0 Turns. Das Fenster 1–30 nimmt die ersten 30 gesprochenen Turns; der
  Anteil in 1–30 ist der kumulative Anteil bei Turn 30.
- Zuordnung über die Projekt-Tags (`status condition`, `Status seat`, `gender composition`) und die
  Sidebar-Gruppe, nicht über den Titel. Die Fokus-Persona ist die Persona mit dem Tag `focus persona: true`,
  in jedem Lauf genau eine (geprüft). Titelträger und die gleiche Persona in der Kontrolle sind getrennte
  Expert-Datensätze, beide tragen das Tag.
- Zeitraum der gesprochenen Turns: 26.09.2026 bis 20.10.2026.

| Serie (Sidebar-Gruppe) | Modell | Pipelines | Läufe | Turns je Lauf | Datum der Läufe |
|---|---|---|---|---|---|
| Pos. Status | gpt-5-mini | Score, Orchestrator, Quality, Quality blind, Round Robin | 40 (je 4 mit Titel, 4 Kontrolle je Pipeline) | 60 | 26.09.–08.10. |
| Pos. Status (Luna) | gpt-5.6-luna (Gateway) | dieselben fünf | 40 | 60 | 19.10. |
| Pos. Status (Haiku) | claude-haiku-4-5 | dieselben fünf | 40 | 60 | 20.10. |
| Pos. Status (Luna, JEV) | gpt-5.6-luna | Jev Score, Orchestrator, Quality, Quality blind | 32 | 60 | 19.–20.10. |
| Neg. Status (Luna) | gpt-5.6-luna | die fünf; Kontrolle = ⚪-Zelle aus Pos. Status (Luna) | 20 | 60 | 19.10. |
| Seat test | gpt-5-mini | Orchestrator, Quality; Titel auf Sitz 1 und 3 | 32 | 60 | 14.10. |
| Gender | gpt-5-mini | Score, Orchestrator, Quality; Dreiergruppen | 48 | 60 | 14.10. |
| Chat length | gpt-5-mini | Score, Orchestrator, Quality, nur mit Titel | 6 | 100 | 08.10. |

Je Zelle (Serie × Pipeline × Bedingung) sind es **4 Läufe**, einer je Thema (School, Office, Family, Sport).
Titel- und Kontrollzelle teilen Thema und Seed 42, deshalb sind die Effekte **gepaart über das Thema**.
Länger als 60 Turns laufen nur die 6 Läufe der Gruppe Chat length, und zwar **ohne Kontrollzelle**: ein
Effekt bei 100 Turns ist mit diesen Daten nicht berechenbar.

## Methode

- **Effekt** = Anteil der Fokus-Persona in der Zelle A (mit Titel, invertiert, Sitz 3, einzige Frau …)
  minus Anteil in der Referenzzelle B, in Prozentpunkten, je Paar (gleiches Thema), dann Mittel der Paare.
  Konfidenzintervall: t-Verteilung über die Paare (bei 4 Paaren mit 3 Freiheitsgraden also breit). Das
  Intervall sagt, ob die Null in der Nähe liegt, nicht mehr; es setzt unabhängige Paare voraus, was beim
  Pooling über Modellfamilien nur teilweise stimmt.
- **Pooling** „gepoolt mini+Luna+Haiku" fasst die drei Serien mit denselben fünf Pipelines zusammen (12 Paare).
  Es mischt Modelle, deren Effekte sich stark unterscheiden (siehe Einzelserien), und ist eine
  Vereinfachung für die Frage „30 gegen 60", kein Modellergebnis. Gemischte Modelle mit Thema und Lauf als
  Zufallseffekten sind in diesem Bericht nicht gerechnet.
- **Stabilitätskriterium unverändert aus dem Bericht vom 08.10.:** ein Lauf ist ab Turn T stabil, wenn sich
  für jeden Experten der kumulative Anteil über die folgenden 10 Turns um höchstens 2 pp ändert und das bis
  zum Laufende so bleibt. Für ein Fenster von W Turns gilt: T ≤ W − 10, bei 30 Turns also T ≤ 20.
  Referenz: 300 simulierte Läufe ohne Dynamik, jeder Turn unabhängig mit den Anteilen des echten Laufs.
  Round Robin geht nur mit dem Wortanteil ein.
- **Gini** über die Turn- bzw. Wortzahlen aller Teilnehmenden im Fenster (Formel aus `Gini::of`; Maximum bei
  vier Agenten 0,75).
- **Gegenprobe:** alle 258 Läufe wurden mit `project:metrics --trajectory --format=csv` verglichen
  (kumulative Turn- und Wortanteile aller Experten bei Turn 30, 60, 100 und am Laufende). Größte Abweichung
  5·10⁻⁷, das ist die Rundung der CSV auf sechs Stellen.

## Ergebnisse

### 1. Titeleffekt auf den Turn-Anteil, 30 gegen 60 gegen 100

Anteil der Fokus-Persona in Prozent, Effekt in Prozentpunkten (mit Titel minus Kontrolle), 95-%-Intervall in
Klammern, „Paare > 0" = in wie vielen der Paare der Titelträger mehr sprach als seine Kontrolle. Mittel aus
12 Paaren (drei Modellfamilien × vier Themen).

| Pipeline | Fenster | Mit Titel | Kontrolle | Effekt [95 %-KI] | Paare > 0 |
|---|---|---|---|---|---|
| Quality | 1–30 | 52 | 25 | **+27,8** [+13; +42] | 11/12 |
| | 1–60 | 52 | 25 | +27,2 [+13; +42] | 11/12 |
| | 1–100 | (nur 2 Läufe, 72 / 78 / 86 % bei 30 / 60 / 100) | – | nicht berechenbar | – |
| Quality blind | 1–30 | 46 | 18 | **+27,8** [+12; +43] | 11/12 |
| | 1–60 | 50 | 21 | +29,7 [+19; +41] | 11/12 |
| | 1–100 | keine Läufe | – | – | – |
| Orchestrator | 1–30 | 37 | 20 | **+17,2** [+8; +26] | 11/12 |
| | 1–60 | 40 | 20 | +20,4 [+9; +32] | 12/12 |
| | 1–100 | (nur 2 Läufe, 13 / 13 / 18 % bei 30 / 60 / 100) | – | nicht berechenbar | – |
| Score | 1–30 | 37 | 25 | **+11,9** [+3; +20] | 11/12 |
| | 1–60 | 38 | 24 | +14,0 [+4; +24] | 11/12 |
| | 1–100 | (nur 2 Läufe, 48 / 53 / 49 % bei 30 / 60 / 100) | – | nicht berechenbar | – |
| Round Robin | 1–30 | 26,7 | 26,7 | 0,0 | 0/12 |
| | 1–60 | 25,0 | 25,0 | 0,0 | 0/12 |

Round Robin: der Turn-Anteil ist per Konstruktion festgelegt. Bei 30 Turns bekommen die Sitze 1 und 2 je
8 Turns (26,7 %), die Sitze 3 und 4 je 7 (23,3 %); die Fokus-Persona sitzt auf Sitz 1. Das ist ein Artefakt
der Fenstergröße (30 ist nicht durch 4 teilbar), kein Effekt.

Wortanteil, gleiche Paare: Quality +28,0 / +28,5, Quality blind +28,0 / +31,3, Orchestrator +18,5 / +21,9,
Score +15,1 / +17,8, Round Robin +2,7 / +2,8 (bei 30 / 60 Turns). Der Wortanteil des Titelträgers liegt
unter Round Robin um 1,6 bis 5 pp über dem der Kontrolle, bei 30 wie bei 60 Turns (9 beziehungsweise 11 von 12
Paaren positiv); er schreibt also mehr, ohne öfter zu sprechen.

### 2. Dieselbe Frage je Serie (Turn-Anteil, Effekt in pp)

Ein Effekt in Klammern, wenn das 95-%-Intervall (4 Paare) die Null einschließt.

| Serie | Pipeline | Effekt 1–30 | Effekt 1–60 | Bemerkung |
|---|---|---|---|---|
| Pos. Status (gpt-5-mini) | Quality | +49,2 | +48,8 | gleich |
| | Quality blind | +45,8 | +43,8 | gleich |
| | Orchestrator | (+26,7) | (+34,6) | wächst, Intervall schließt 0 ein |
| | Score | (+22,5) | +31,7 | wächst |
| Pos. Status (Luna) | Quality | +21,7 | +23,3 | gleich |
| | Quality blind | (+12,5) | (+20,4) | wächst |
| | Orchestrator | +12,5 | +12,9 | gleich |
| | Score | +10,0 | +4,2 | **schrumpft auf weniger als die Hälfte** |
| Pos. Status (Haiku) | Quality blind | (+25,0) | +25,0 | gleich |
| | Orchestrator | (+12,5) | (+13,8) | gleich |
| | Quality | (+12,5) | (+9,6) | gleich |
| | Score | (+3,3) | (+6,3) | nicht unterscheidbar von 0 |
| Jev (Luna) | Jev Quality | +31,7 | +30,4 | gleich |
| | Jev Orchestrator | (+35,0) | (+38,8) | gleich, sehr breit |
| | Jev Quality blind | (+20,0) | +17,9 | gleich |
| | Jev Score | (+6,7) | (−0,4) | **verschwindet** |

Das Vorzeichen (nach Rundung auf 0,1 pp) wechselt zwischen 30 und 60 Turns nur an einer Stelle des
Titeleffekts: Jev Score, und dort liegt der Effekt bei 60 Turns bei null. Im Wortanteil fällt er dort von +8,7 auf +1,0, ohne das Vorzeichen zu wechseln.

### 3. Stabilität und Bewegung nach Turn 30

Anzahl der Läufe, die das vorab festgelegte Kriterium erfüllen (kumulativer Turn-Anteil aller Experten;
Läufe der Serien Pos./Neg. Status, Jev, Seat test, Gender; ohne die sechs 100-Turn-Läufe). Referenz = mittlere
Wahrscheinlichkeit eines Prozesses ohne Dynamik, das Kriterium zu erfüllen.

| Pipeline | Läufe | stabil bei 30 | Referenz 30 | stabil bei 60 | Referenz 60 |
|---|---|---|---|---|---|
| Score | 44 | 0 | 0 % | 0 | 4 % |
| Orchestrator | 60 | 1 | 5 % | 16 | 20 % |
| Quality | 60 | 0 | 2 % | 9 | 19 % |
| Quality blind | 28 | 0 | 0 % | 1 | 8 % |
| Jev Orchestrator | 8 | 0 | 2 % | 6 | 22 % |
| Jev Score / Quality / Quality blind | je 8 | 0 | 0–3 % | 0 / 0 / 1 | 3–5 % |
| Round Robin (nur Wortanteil) | 28 | 0 | – | 22 | – |

**30 Turns erfüllen das Kriterium nicht.** Aber auch bei 60 Turns tun es nur Orchestrator und Round Robin
häufiger, und die Beobachtung liegt dort nahe an der Referenz ohne Dynamik (Orchestrator 27 % gegen 20 %,
Quality 15 % gegen 19 %). Das Kriterium unterscheidet 30 und 60 Turns darum kaum; es belegt vor allem, dass
der kumulative Anteil sich bei beiden Längen noch bewegt. Der gleitende Anteil (Fenster 20) erfüllt es noch
seltener.

**Wie weit sich der kumulative Turn-Anteil der Fokus-Persona nach Turn 30 noch bewegt (Betrag der Änderung von
Turn 30 bis 60, in pp; Mittel / Maximum über die Läufe).** Je Zeile 12 bis 20 Läufe; ohne Round Robin, bei dem
nur der Rundungsrest 30→60 (26,7 → 25,0) auftritt.

| Pipeline | Mit Titel (Mittel / Max) | Kontrolle (Mittel / Max) | größte Bewegung irgendeines Experten (Max) |
|---|---|---|---|
| Score | 6,9 / 16,7 | 4,0 / 11,7 | 16,7 (Gender-Läufe 25,0) |
| Orchestrator | 4,1 / 11,7 | 4,3 / 30,0 | 30,0 |
| Quality | 5,1 / 15,0 | 6,1 / 20,0 | 21,7 (Gender-Läufe 26,7) |
| Quality blind | 7,8 / 11,7 | 6,9 / 25,0 | 25,0 |

Ein Titelträger bewegt sich nach Turn 30 im Mittel um 4 bis 8 pp, im Einzelfall um bis zu 17 pp (Wortanteil
ähnlich: 11 bis 18 pp Maximum). Das Vorzeichen der Bewegung ist im Mittel schwach positiv (Quality +1,2,
Orchestrator +1,3, Score +1,4, Quality blind +4,4 pp), bei Jev Score negativ (−6,2). Die Mittel sind klein
gegenüber der Bewegung der Einzelläufe: die Läufe driften in beide Richtungen.

Zeitblöcke (Turn-Anteil der Fokus-Persona in Blöcken à 10 Turns, Mittel über die Läufe der Pos.-Serien, in %):

| Pipeline | Bedingung | 1–10 | 11–20 | 21–30 | 31–40 | 41–50 | 51–60 |
|---|---|---|---|---|---|---|---|
| Quality | Titel (20) | 57 | 62 | 64 | 58 | 61 | 70 |
| | Kontrolle | 22 | 30 | 29 | 28 | 27 | 24 |
| Orchestrator | Titel (20) | 42 | 40 | 44 | 46 | 40 | 46 |
| | Kontrolle | 24 | 20 | 20 | 17 | 26 | 23 |
| Score | Titel (12) | 41 | 34 | 35 | 43 | 38 | 38 |
| | Kontrolle | 23 | 22 | 29 | 27 | 22 | 22 |
| Quality blind | Titel (12) | 30 | 50 | 58 | 53 | 58 | 52 |
| | Kontrolle | 18 | 20 | 16 | 15 | 28 | 26 |
| Jev Score | Titel (4) | 32 | 35 | 32 | 28 | 18 | 18 |
| | Kontrolle | 25 | 28 | 27 | 32 | 20 | 32 |

Unter Quality, Orchestrator und Score ist der Abstand schon in den ersten 10 Turns da und bleibt danach
ungefähr gleich. Unter Quality blind baut er sich erst auf (30 % in Turn 1–10, 50 % und mehr ab Turn 11):
**Im Fenster 1–30 ist er also noch im Aufbau, im Fenster 1–60 voll da**, was zum Anstieg des Effekts von
+27,8 auf +29,7 (gepoolt) beziehungsweise bei Luna von +12,5 auf +20,4 passt. Jev Score fällt im Verlauf auf
den Bereich der Kontrolle ab (18 % gegen 20 bis 32 %).

**Die sechs 100-Turn-Läufe (Chat length, nur mit Titel, gpt-5-mini):** Turn-Anteil des Titelträgers in Prozent.

| Pipeline, Thema | bei 30 | bei 60 | bei 100 | Δ 30→100 | Δ 60→100 |
|---|---|---|---|---|---|
| Score, School | 40,0 | 53,3 | 49,0 | +9,0 | −4,3 |
| Score, Office | 56,7 | 51,7 | 49,0 | −7,7 | −2,7 |
| Orchestrator, School | 10,0 | 8,3 | 13,0 | +3,0 | +4,7 |
| Orchestrator, Office | 16,7 | 16,7 | 22,0 | +5,3 | +5,3 |
| Quality, School | 70,0 | 75,0 | 82,0 | +12,0 | +7,0 |
| Quality, Office | 73,3 | 81,7 | 89,0 | +15,7 | +7,3 |

Kriterium: bei 30 in keinem der sechs Läufe erfüllt, bei 60 in einem (Orchestrator, Office), bei 100 in vier
(Turn-Anteil; T = 90, 82, 88, 70). Das stimmt mit dem Bericht vom 08.10. überein. Die Läufe sind Kopien
älterer 60-Turn-Läufe, aber **keine Replikate** (Stage-Prompts geändert, siehe Bericht vom 08.10.); sie
taugen für den Verlauf innerhalb eines Laufs, nicht für den Vergleich mit den 60-Turn-Originalen.
**Nur unter Quality bewegt sich der Titelträger systematisch weiter nach oben** (zwei von zwei Läufen, +12 und
+16 pp von Turn 30 bis 100); unter Score und Orchestrator ist die Richtung uneinheitlich. Der Befund stammt
aus zwei Läufen.

### 4. Gini

Mittlerer Gini der Turn-Verteilung (Maximum 0,75), gepoolt mini+Luna+Haiku, bei 30 / 60 Turns:

| Pipeline | mit Titel | Kontrolle |
|---|---|---|
| Quality | 0,38 / 0,37 | 0,33 / 0,28 |
| Quality blind | 0,37 / 0,37 | 0,25 / 0,23 |
| Orchestrator | 0,29 / 0,31 | 0,29 / 0,31 |
| Score | 0,24 / 0,21 | 0,18 / 0,15 |
| Round Robin | 0,03 / 0,00 | 0,03 / 0,00 |

Gini ändert sich zwischen 30 und 60 Turns um höchstens 0,05 und liegt für die Kontrollen unter
Orchestrator auf gleichem Niveau wie für die Titelzellen (der Titel verschiebt dort *wer* dominiert, nicht
*wie ungleich* verteilt wird). Round Robin 0,03 bei 30 ist wieder die Fenstergröße (8, 8, 7, 7 Turns).

### 5. Vorzeichen und Rangfolge

- **Titeleffekt:** Vorzeichen und Größenordnung gleich bei 30 und 60, außer Jev Score (siehe oben) und
  einzelnen Paaren. Die Rangfolge der Pipelines nach Effekt ist in jeder der fünf Einzelserien bei 30 und 60
  identisch (Turn- und Wortanteil). Gepoolt tauschen die beiden Quality-Varianten (gleichauf bei 30:
  +27,8 beide; bei 60: Quality blind +29,7 vor Quality +27,2). Reihenfolge gepoolt: Quality ≈ Quality blind
  > Orchestrator > Score > Round Robin.
- **Neg. Status (Luna, invertiert minus Kontrolle, Turn-Anteil bei 30 / 60):** Quality blind −17,5 / −16,7,
  Orchestrator −5,8 / −4,6, Quality −5,0 / −3,3, Round Robin 0,0 / 0,0, **Score +5,8 / +3,7 (entgegen der
  Erwartung, 2 bis 3 von 4 Paaren positiv, Intervall schließt 0 ein)**. Vorzeichen und Rangfolge identisch bei
  30 und 60; bei 4 Paaren je Zelle ist nur Quality blind bei 60 Turns klar von 0 getrennt
  (Intervall [−29; −4]).
- **Sitztest (Orchestrator, Quality):** Titeleffekt auf Sitz 1 bei 30 / 60: +27,5 / +29,6 beziehungsweise
  +43,3 / +50,0; auf Sitz 3: +23,3 / +16,2 beziehungsweise +42,5 / +45,0. Die Intervalle sind mit ±70 pp bei
  Orchestrator Sitz 3 und ±60 bis 80 bei Quality so breit, dass weder die Richtung des Sitz-Unterschieds
  noch seine Größe bei 30 oder 60 belegt ist. **Das Vorzeichen des Sitz-3-gegen-Sitz-1-Effekts wechselt
  in der Orchestrator-Kontrolle** (0,0 bei 30, +12,9 bei 60). Aus diesen Zahlen lässt sich ein
  Sitzeffekt weder bestätigen noch ausschließen, bei 30 Turns genauso wenig wie bei 60.
- **Gender (Dreiergruppen, Turn-Anteil der Fokus-Persona bei 30 / 60):** einziger Mann minus Mann unter Männern:
  Quality +32,5 / +38,3 (4/4 Paare, bei beiden Fenstern von 0 getrennt), Score +17,5 / +15,4, Orchestrator
  −10,0 / −6,7. Einzige Frau minus Frau unter Frauen: Orchestrator +12,5 / +12,1, Score +5,0 / +0,4, **Quality
  −2,5 / +7,1 (Vorzeichenwechsel, Intervall ±70)**. Frau unter Frauen minus Mann unter Männern:
  Orchestrator −18,3 / −15,8 (0/4), Quality +10,0 / +5,8, Score +3,3 / +3,7. Alle Zellen haben 4 Läufe, die
  Intervalle schließen bis auf „einziger Mann, Quality" die 0 ein. Die Richtung von „einziger Mann
  spricht mehr" bleibt bei 30 Turns erhalten (gleiche Reihenfolge der Pipelines, ein Vorzeichenwechsel bei
  einer Zelle mit sehr breitem Intervall).

### 6. Streuung und Power (grobe Schätzung)

**Streuung zwischen den Läufen.** Verhältnis der Standardabweichung (Zelle, 4 Läufe) bei 30 zu der bei 60
Turns, über alle Titelserien und Pipelines ohne Round Robin beim Turn-Anteil: Median des Verhältnisses
für die Titelzellen 0,97 (Mittel 1,02), für die Kontrollzellen 1,06 (Mittel 1,22), für die gepaarten Differenzen 1,01
(Mittel 1,07); beim Wortanteil 0,97 / 1,11 / 1,00. **Die Streuung ist bei 30 Turns nicht erkennbar
größer als bei 60.** Sie liegt bei 13 bis 25 pp (SD der gepaarten Differenzen, gepoolt) und kommt aus Thema,
Modell und dem Lauf selbst. Dass 30 und 60 nicht stärker streuen, passt dazu, dass die Läufe sich nach Turn 30 kaum
systematisch bewegen (Abschnitt 3); das ist eine Vermutung, keine geprüfte Erklärung.
Der 100-Turn-Vergleich entfällt (sechs Läufe, keine Kontrollen).

**Läufe für dieselbe Aussagekraft.** Grobe Normalnäherung für gepaarte Differenzen (zweiseitig 5 %, Power 80 %,
Formel n = (2,80 · SD / Effekt)², ohne t-Korrektur) mit den gepoolten Werten, in Paaren (Thema × Modell):

| Pipeline | Paare für den Effekt bei 30 | Paare für den Effekt bei 60 | Paare bei 30, um den Effekt von 60 zu finden |
|---|---|---|---|
| Quality | 5,3 | 5,4 | 5,5 |
| Quality blind | 6,2 | 2,6 | 5,4 |
| Orchestrator | 5,6 | 6,0 | 4,0 |
| Score | 9,9 | 9,5 | 7,2 |

Für die gepoolten Effekte reichen demnach etwa 5 bis 10 Paare bei beiden Längen, bei 30 Turns **nicht mehr** als bei
60 (außer Quality blind, wo der Effekt bei 30 noch im Aufbau ist). Die Schätzung gilt nur für die
gepoolten, großen Effekte; in den Einzelserien mit 4 Paaren sind SD und Effekt zu unsicher, um sinnvoll
nach Läufen zu rechnen (Haiku Score: 146 Paare bei 30, 17 bei 60; Jev Score: 4 bei 30, 534 bei 60, weil der Effekt
klein wird oder verschwindet; die Werte stehen im JSON unter `power`).

## Gemessen und vermutet

Gemessen:

- Der Titeleffekt auf Turn- und Wortanteil ist bei 30 Turns sichtbar und hat dasselbe Vorzeichen wie bei 60;
  bei Quality, Quality blind, Orchestrator und Score gepoolt in 11 von 12 Paaren (Orchestrator bei 60 Turns:
  12 von 12).
- Das Einpendel-Kriterium erfüllt nach 30 Turns praktisch niemand, nach 60 Turns eine Minderheit, ähnlich
  oft wie ein Prozess ohne Dynamik.
- Score und Jev Score ändern ihren Effekt zwischen 30 und 60 Turns deutlich (Luna Score von +10,0 auf +4,2,
  Jev Score von +6,7 auf −0,4, gpt-5-mini Score von +22,5 auf +31,7), die anderen Pipelines nicht.
- Unter Quality liegen die beiden 100-Turn-Läufe bei 100 Turns 12 und 16 pp über ihrem Wert bei Turn 30.
- Die Streuung zwischen den Läufen ist bei 30 und 60 Turns etwa gleich groß.

Vermutet, nicht gezeigt:

- Der Wachstumstrend unter Quality ist ein Rich-get-richer-Verlauf; mit zwei Läufen ohne Kontrolle und
  anderem Prompt-Stand als die 60-Turn-Originale belegt das nur der Verlauf, nicht seine Ursache.
- Der Abfall bei Jev Score und Luna Score kann daran liegen, dass der Titel in den ersten Turns wirkt und
  durch die Priorisierung verblasst; die Daten zeigen nur das Verschwinden, nicht den Mechanismus.
- Die Aussage „Richtung und Größenordnung bleiben bei 30 Turns erhalten" beruht auf der Pooling-Annahme; sie
  verdeckt, dass die Modelle sehr unterschiedliche Effekte haben (Quality: +49 mit gpt-5-mini, +23 mit
  Luna, +10 mit Haiku).

## Grenzen

- **n ist klein:** 4 Läufe je Zelle, 12 Paare gepoolt. Intervalle sind breit; vieles ist nicht von 0
  unterscheidbar. Die Intervalle setzen unabhängige Paare voraus; Läufe desselben Themas und Seeds teilen
  Randbedingungen.
- **Pooling über drei Modellfamilien** mischt gpt-5-mini, Luna (Gateway) und Haiku; die Serien liefen zu
  verschiedenen Zeiten (26.09. bis 20.10.) mit verschiedenen Prompt-Ständen. Besonders die Pos.-Status-Serie
  mit gpt-5-mini besteht aus Läufen von vier verschiedenen Tagen (Score 26./27.09., Orchestrator 29.09.,
  Quality/Round Robin 01.10., Quality blind 08.10.), vor und nach Prompt-Änderungen. Serien- und
  Pipelinevergleiche sind darum nicht rein.
- **Keine Kontrolle bei 100 Turns.** Alle Aussagen zum Fenster 1–100 stützen sich auf sechs Läufe mit Titel
  und sind Verlaufsbeobachtungen, kein Effekt.
- **Kein gemischtes Modell**, keine Varianzzerlegung: Thema, Modell und Lauf werden hier nicht als
  Zufallseffekte geschätzt, nur gepaart über das Thema. Die Power-Schätzung ist eine Normalnäherung.
- **Der Anteil bei 30 ist Teil des Anteils bei 60.** Die Fenster sind nicht unabhängig; „Effekt bei 30
  gegen bei 60" vergleicht verschachtelte Stichproben. Die Differenz der beiden ist deshalb kleiner als bei
  unabhängigen Läufen zu erwarten.
- **Round Robin** hat per Konstruktion keinen Turn-Anteil-Effekt; die 26,7 % bei 30 Turns sind ein
  Fensterartefakt (Sitz 1 und 2 sprechen 8-mal, Sitz 3 und 4 7-mal).
- **Stabilitätskriterium bei 30 Turns:** T ≤ 20, bei 60 Turns T ≤ 50 (Rechtszensur). Die Referenz ohne
  Dynamik erfüllt es bei 30 Turns kaum, das Kriterium ist hier also kein scharfes Maß für „Chat lang genug".
- Nicht ausgewertet: Übernahme der Position des Titelträgers, Reasoning-Anteil, Fehlerraten, die Serie
  „Origin" (nicht gelaufen), Pipelineinterne Signale (Gebote, Judge-Auswahl).
- Die Datenbank-Kopie enthält Läufe bis 20.10.2026; spätere Läufe sind nicht berücksichtigt.

## Für die Thesis

Diese Befunde sollten als Einschränkung genannt werden (nur Wiedergabe von oben, keine neuen Befunde):

- Läufe mit 30 Turns sind mit den bisherigen 60-Turn-Läufen nicht direkt vergleichbar.
- Der Score-Effekt ist längenabhängig (gpt-5-mini +22,5 auf +31,7, Luna +10,0 auf +4,2, Jev Score +6,7 auf −0,4).
- Das Einpendel-Kriterium ist bei 30 Turns kaum erfüllt (und bei 60 kaum öfter als ohne Dynamik).
- Es gibt keine Kontrollen bei 100 Turns; der Anstieg unter Quality nach Turn 30 stammt aus zwei Läufen ohne Kontrolle.
- 4 Läufe je Zelle, 12 Paare gepoolt.
- Pooling mischt Modelle und Prompt-Stände.
