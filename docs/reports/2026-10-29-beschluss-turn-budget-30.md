# Beschluss: Turn-Budget 30 je Lauf (vorläufig, Stand 29.10.2026)

**Beschluss (Jan-Niclas, 29.10.2026):** Die Läufe der Studie bekommen **erstmal ein Turn-Budget von 30 Turns**. Ein Turn
ist ein gesprochener Beitrag (eine Nachricht); gemeint sind 30 Beiträge je Lauf insgesamt, nicht 30 Runden. Der Beschluss
gilt **vorläufig** („erstmal"). Er ersetzt die bisherige Schätzung von bis zu 150 Nachrichten, die das Budget zu stark
belastet, und die Empfehlung von 100 aus dem [Zwischenbericht](2026-10-18-zwischenbericht-statuseffekt.md) (3.3).

## Begründung aus dem Quellen-Archiv der Masterarbeit

Es gibt keine Studie, die zeigt, dass LLM-Diskussionen nach etwa 50 Nachrichten das Thema wechseln. Die folgenden
Quellen stützen aber ein kurzes Budget:

- **Hu2026** (*Multi-agent AI systems outperform human teams in creativity*, arXiv 2605.17885): Diskussionslänge
  **30 vs. 60 Turns ohne Effekt** (Zielgröße Kreativität, nicht Redeanteil). Laut Archivnotiz, nicht im Volltext geprüft.
- **Nonomura2025** (*Who speaks next?*): Das System hat Schwierigkeiten, das Langzeitgedächtnis in Dialogen von etwa
  30 Turns zu halten, und zeigt Themenabweichung zwischen den Agenten. Das ist eine Schwäche des dortigen Systems,
  nicht quantifiziert und kein Nulleffekt.
- **Vijjini2026** (Power-Asymmetric Conversations): Statuseffekte sind in den frühen Turns am stärksten und
  klingen ab (Dyaden, 10–15 Turns, zwei Modelle).
- **Becker2025** (*Stay Focused: Problem Drift*): Problem Drift bei langen Debatten, in 99 % der Fälle steht die erste
  Einigung nach 2 Turns. Drift ist dort ein Leistungsabfall, kein Themenwechsel; untersucht sind nur 7 Turns.
- **Hu2025** (Adaptive Stop): Abbruch nach 2–8 Runden bei höchstens etwa 1 Prozentpunkt Verlust gegenüber 10 Runden.

Die Quellen sind ein Plausibilitätsargument mit unterschiedlichen Zielgrößen, kein harter Beleg für 30 Turns.

## Widerspruch zu den eigenen Läufen

Der Zwischenbericht vom 18.10. kommt für **Redeanteil** zum entgegengesetzten Ergebnis:

- Nach **60 Turns** erfüllen nur **4 von 24 Läufen** das vorab festgelegte Stabilitätskriterium (kumulativer Anteil ändert
  sich über die folgenden 10 Turns um höchstens 2 Prozentpunkte), und zwar Extremfälle.
- Der Anteil des Titelträgers bewegt sich zwischen Turn 60 und 100 noch um **3 bis 7 Prozentpunkte**, unter Quality
  systematisch nach oben (School 75 → 82 %, Office 82 → 89 %). Ein kurzer Lauf unterschätzt dort den Effekt.
- Mit 100 Turns ist der Wortanteil in allen sechs Läufen stabil.
- Der im Bericht genannte Detailbericht `docs/studie/2026-10-08-chatlaenge.md` ist im Repository nicht auffindbar.

**Folgen von 30 Turns:**

- Läufe mit 30 Turns sind mit den bisherigen 60-Turn-Läufen (126 Läufe plus Sitz- und Gender-Test) nicht direkt
  vergleichbar.
- Der Statuseffekt unter Quality wird bei 30 Turns voraussichtlich noch stärker unterschätzt als bei 60.
- Die Kosten je Lauf sinken grob um die Hälfte (nicht nachgerechnet; das Kontextfenster wächst mit der Turn-Zahl).

## Offen / Vorschlag zur Absicherung

- Mit der vorhandenen Trajektorie (`ShareTrajectory`, kumulativer Anteil je Turn) für die bestehenden Läufe den Anteil
  **bei Turn 30** gegen den Endwert bei Turn 60 vergleichen. Weicht er mehr als 2 Prozentpunkte ab, sollte der Beschluss
  überprüft oder in der Arbeit ausdrücklich als Einschränkung genannt werden.
- Dasselbe getrennt je Mechanismus auswerten, besonders Quality.
- In der Thesis die Wahl von 30 Turns mit den Quellen oben begründen und die Abweichung zu den eigenen Läufen offenlegen.
