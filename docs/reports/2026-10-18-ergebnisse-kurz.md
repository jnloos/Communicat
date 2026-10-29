# Ergebnisse auf einen Blick (Stand 18.10.2026)

Kurzfassung der neuen Befunde seit dem Bericht vom 01.10.2026. Details, Tabellen und Einschränkungen stehen im
[Zwischenbericht](2026-10-18-zwischenbericht-statuseffekt.md), das Vorhaben erklärt der
[Initialbericht](2026-10-18-initialbericht-vorhaben.md).

**Grundlage:** 126 Läufe mit 7.800 gesprochenen Beiträgen, alle auf `gpt-5-mini`, je Zelle ein Lauf pro Thema (vier
Themen), Seed 42. Alle Zahlen sind deskriptiv: Hinweise, keine Befunde.

## 1. Der Statuseffekt zeigt in dieselbe Richtung

In **29 von 32** Paarvergleichen mit freier Sprecherwahl spricht die Persona mit Titel häufiger als dieselbe Persona
ohne Titel (Score 4/4, Orchestrator 10/12, Quality 11/12, Blind-Quality 4/4). Die Paare sind nicht unabhängig
(gleiche Themen und Seed), die Zahl trägt deshalb nur als Richtung.

| Mechanismus | mit Titel | ohne Titel | Differenz |
|---|---|---|---|
| Score | 56,7 % | 25,0 % | +31,7 |
| Orchestrator (Sitz 1) | 42,5 % | 7,9 % | +34,6 |
| Quality (Sitz 1) | 85,0 % | 36,2 % | +48,8 |
| Round Robin (Wortanteil) | 31,4 % | 26,1 % | +5,2 |

Ein Agent dominiert auch ohne Titel. Der Titel erzeugt die Ungleichheit nicht, er bestimmt, auf wen sie fällt.

## 2. Blinder Judge: Der Titel wirkt auch, wenn der Judge ihn nicht sieht

| Judge | mit Titel | ohne Titel | Differenz |
|---|---|---|---|
| sieht die Rollen | 85,0 % | 36,2 % | +48,8 |
| blind (kein Name, keine Rolle) | 66,2 % | 22,5 % | +43,8 |

Die Sichtbarkeit erklärt nur etwa **5 von knapp 49 Prozentpunkten**. Der Rest läuft über den Inhalt der Entwürfe.
Die Variante lässt Selbstbezüge und Namen in den Entwürfen absichtlich offen.

## 3. Sitztest: Die Position zählt, beim Orchestrator deutlich

| Mechanismus | Titel auf Sitz 1 (Wiederholung) | Titel auf Sitz 3 |
|---|---|---|
| Quality | +50,0 (4 von 4 Paaren) | +45,0 (3 von 4) |
| Orchestrator | +29,6 (4 von 4) | **+16,2 (2 von 4)** |

Quality ist weitgehend sitzunabhängig. Der Orchestrator bevorzugt mittlere Sitze, das drückt den Effekt auf Sitz 3.
Wiederholungen derselben Zelle schwanken je Thema um bis zu 45 Prozentpunkte.

## 4. Chatlänge: 100 Turns statt 60

Nach 60 Turns erfüllen nur 4 von 24 Läufen das vorab festgelegte Stabilitätskriterium. Unter Quality wächst der Anteil
des Titelträgers bis Turn 100 weiter (+7 Prozentpunkte). Empfehlung: **100 Turns**. Sitztest und Gender-Test (80 Läufe)
liefen trotzdem mit 60, ihre Effekte sind unter Quality vermutlich unterschätzt.

## 5. Gender (Nebenzweig): Der einzige Mann gewinnt mehr als die einzige Frau

Dreiergruppen, nur der Vorname wechselt (Anna ↔ Mark). Bezeichnung der Zellen: ♀️♀️♀️ alle Frauen, ♀️♀️♂️ einziger Mann,
♂️♂️♂️ alle Männer, ♂️♂️♀️ einzige Frau.

| Vergleich (Prozentpunkte Turn-Anteil) | Score | Orchestrator | Quality | positive Paare (von 12) |
|---|---|---|---|---|
| ♀️♀️♂️ − ♀️♀️♀️ (Mann in Frauengruppe) | +11,7 | +9,2 | +32,5 | 8 |
| ♂️♂️♀️ − ♂️♂️♂️ (Frau in Männergruppe) | +4,2 | −3,7 | +12,9 | 6 |
| ♀️♀️♂️ − ♂️♂️♀️ (einziger Mann − einzige Frau) | +11,3 | −2,9 | +25,4 | 8 |

Manipulationscheck: „he/him/his“ kommt in 720 Beiträgen mit Mark 106-mal vor, ohne Mann 23-mal. Mann gegen Frau:
acht von zwölf Paaren positiv, Mittel +11,2 Prozentpunkte, p ≈ 0,19. Ein Hinweis, kein Befund.

## 6. Technik und Kosten

- 33.848 LLM-Aufrufe, 19 Turns mit Status *failed* (0,24 %), alle Läufe haben ihr Budget erreicht, keine unlesbaren
  Selektor-Antworten bei Quality, Orchestrator und Blind-Quality.
- Bei Score entscheidet in **56 %** der Turns der Zufall unter den Höchstbietenden (grobe Skala 1–5).
- Kosten rund **111 $** für alle 126 Läufe (aus den Token-Zählern gerechnet). Pro Lauf bei 60 Turns: Round Robin
  ≈ 0,40 $, Orchestrator ≈ 0,50 $, Score ≈ 1,00 $, Quality ≈ 1,25 $.

## 7. Offen

- **Persona-/Status-Layer** mit Rotation über alle Sitze (Sitz 2 und 4 fehlen, Score und Round Robin nicht im
  Sitztest).
- **Auswertungsskript** (Mixed Models, Entropie, Varianzzerlegung) und **`RunExperiment`** (Matrix-Runner).
- **Poweranalyse** und Wiederholungen mit anderen Seeds, bisher nur ein Seed.
- **Modell:** Der Betreuer will es festsetzen, welches und ob nur eine Familie, ist offen.
- Kontrolle durch Etikett-Tausch statt neutralem Zusatztext: Zustimmung des Betreuers steht aus.
