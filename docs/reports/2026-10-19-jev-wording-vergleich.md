# Jev als Orchestrator: Vergleich von vier Frageformulierungen (Stage-0-Replay)

Stand 19.10.2026. Rohdaten, Skripte und der Cache der Jev-Antworten liegen in `docs/reports/2026-10-19-jev-wording/` (Wiederholung: `tar xzf cache.tar.gz`, dann `python3 -I analyze.py`; `boot.php`/`lib.php` tragen noch die Scratchpad-Pfade der Session, vor einem neuen Lauf anpassen).

## Frage

Die Frage, die `JevSpeakerSelector` Jev stellt (V0), lautet „Choose exactly one person … who contributes the most to the conversation right now. You may choose the same person several times in a row if that is factually correct.“ Das erste Replay (siehe Doc in Claude.ai) zeigte: Jev beantwortet das als Zählaufgabe und wählt in 96,6 % der Zustände den bisherigen Vielsprecher. Live würde daraus ein Rich-get-richer-Kreislauf. Hier prüfen wir, ob eine andere Formulierung das Zählen beendet, ohne dass Jev etwas anderes Auffälliges tut.

## Aufbau

- **Zustände:** 12 Luna-Orchestrator-Zellen (Pos. Status 185/187/189/191, Eq-Kontrolle 186/188/190/192, Neg. Status 241–244) × 60 Turns = 720 Zustände. Die History wird wie im Lauf aus der Gleitfenster-Logik von `Summarize` rekonstruiert (Watermark gegen die echten Läufe geprüft). Long-Term-Memory ist leer, weil es pro Turn nicht gespeichert wurde.
- **Varianten der Entscheidungsfrage** (alles andere, Zustandstext und Optionen, identisch):
  - V0: heutiger Text.
  - V1: „… who is most likely to speak next.“
  - V2: „… whose turn it is to speak next.“
  - V3: „… who should speak next.“
  - V1–V3 enden mit „You may choose the same person several times in a row.“ (ohne „if that is factually correct“, das sich auf das Zählen bezog; das ist ein zweiter Unterschied zu V0).
- **Reihenfolge:** Teilnehmerliste und Optionen in der Reihenfolge des Laufs (`own`) oder pro Turn gemischt mit `SeededShuffle` (`shuf`).
- **Roster:** mit den Rollen des Laufs (`own`) oder getauscht (`alt`: Pos/Neg bekommen die gleichen Rollen der Eq-Zwillingszelle, Eq-Zellen die Titelrollen der Pos-Zelle). Daraus der gepaarte Rolleneffekt auf P(Fokuspersona).
- 4 × 2 × 2 × 720 = 11.520 Calls an `jev-1.13.0`, 0 Fehler, 0 unlesbare Antworten, nur lesend auf der Datenbank, keine `prompt_logs`-Zeilen.

## Ergebnisse

Referenzwerte aus den aufgezeichneten Luna-Läufen: Luna wiederholt den letzten Sprecher in 3,2 % der Turns, ist in 31,8 % der Turns einer der Vielsprecher der History und folgt dem Adressaten der letzten Nachricht in 23,9 % (n = 549; Zufall 25 %).

### Verhalten (Roster wie im Lauf, gemischte Reihenfolge)

| Variante | Argmax = Vielsprecher | = letzter Sprecher | = Adressat | = Lunas Sprecher | Entropie (bit) | Argmax = zuerst gelistet |
|---|---|---|---|---|---|---|
| V0 heute | 97,2 % | 41,7 % | 36,2 % | 24,7 % | 0,60 | 32,2 % |
| V1 „am wahrscheinlichsten als Nächstes“ | 35,2 % | 1,6 % | 20,6 % | 52,4 % | 1,31 | 27,9 % |
| V2 „ist als Nächstes dran“ | 29,5 % | 0,3 % | 15,3 % | 51,8 % | 1,27 | 26,0 % |
| V3 „sollte als Nächstes sprechen“ | 41,9 % | 6,8 % | 21,3 % | 51,2 % | 1,35 | 24,9 % |

In der Reihenfolge des Laufs sieht es gleich aus (siehe `results-wording.json`); dort ist der Positionsbias größer (zuerst gelistet: V0 48,1 %, V1–V3 31–32 %).

### Rolleneffekt auf P(Fokuspersona), gepaart, gemischte Reihenfolge

Differenz mit Titel- bzw. Niedrigrolle minus gleiche Rollen, 95-%-Intervall aus einem Bootstrap innerhalb der Zellen (die zwölf Zellen selbst gelten als fest).

| Variante | Pos. Status | Neg. Status | Eq + Titel |
|---|---|---|---|
| V0 | −2,2 pp [−2,9; −1,4] | −0,5 pp [−0,8; −0,1] | +2,9 pp [+2,0; +3,9] |
| V1 | +5,0 pp [+3,8; +6,3] | −6,0 pp [−7,0; −5,0] | +9,8 pp [+8,5; +11,2] |
| V2 | +6,9 pp [+5,6; +8,1] | −6,2 pp [−7,1; −5,2] | +10,4 pp [+9,1; +11,8] |
| V3 | +7,9 pp [+6,6; +9,3] | −6,0 pp [−7,0; −5,1] | +17,5 pp [+16,1; +19,1] |

V0 ist gesättigt: P(Fokus) liegt in Pos-Zellen bei 90 %, in Neg-Zellen bei 7 %, weil der Vielsprecher aus dem Verlauf schon alles entscheidet. Der kleine, teils falsch gerichtete Rolleneffekt ist dort kein Zeichen von Statusblindheit, sondern von Sättigung.

### Stabilität (Argmax bleibt gleich bei …)

| Variante | Reihenfolge-Wechsel | Roster-Tausch (gemischt) |
|---|---|---|
| V0 | 91,4 % | 95,4 % |
| V1 | 82,2 % | 81,8 % |
| V2 | 82,4 % | 81,2 % |
| V3 | 80,1 % | 78,1 % |

V0 ist nur deshalb stabil, weil es fast immer dieselbe Person nimmt.

## Lesart

1. **Das Wording war die Ursache des Zählens.** Alle drei Alternativen lösen sich vom Vielsprecher (30–42 % bei einem Zufallswert von 34 %, Luna 32 %) und wiederholen den letzten Sprecher kaum noch (0,3–6,8 %, Luna 3,2 %).
2. **Der Adressat wird auch von Luna nicht bevorzugt** (23,9 %). Dass V1–V3 mit 15–21 % darunter liegen, ist deshalb kein Mangel gegenüber dem Team-Orchestrator; V0 liegt mit 36 % darüber.
3. **Mit den neuen Formulierungen reagiert Jev auf die Rolle.** Titel und Niedrigrolle verschieben P(Fokus) um 5–8 pp (Pos) bzw. ca. −6 pp (Neg); in V0 war das hinter der Sättigung nicht zu sehen. Das ist für die Studie kein Fehler (ein Selektor darf einen Statuseffekt zeigen, das ist die Messgröße), aber es ist ein Eigenschaftsunterschied der Varianten. V3 („sollte“) reagiert am stärksten (+17,5 pp bei Eq + Titel), die normative Formulierung lädt zum Urteil über Autorität ein.
4. **Gemischte Reihenfolge verringert den Positionsbias** (V1–V3: zuerst gelistet 25–28 % statt 31–32 %; V0 32 % statt 48 %), bleibt aber ein von der Formulierung unabhängiger Eingriff.

## Empfehlung

**V1 („who is most likely to speak next“) mit pro Turn gemischter Reihenfolge.**

- V1 beschreibt, was passiert, statt zu sagen, was sein soll. Das passt zum Orchestrator der Studie, der Sprecher nur auswählt und nie anweist (der Team-Orchestrator wählt ebenfalls „wer trägt jetzt am meisten bei“, also eine Beschreibung, keine Norm).
- V1 und V2 liegen bei Vielsprecher und letztem Sprecher am nächsten an Luna. V3 wiederholt doppelt so oft den letzten Sprecher wie Luna und zeigt den größten Rolleneffekt, also die größte Eigenverschiebung durch die Formulierung.
- V1 vor V2: V2 („ist dran“) hat den niedrigsten Adressatenwert (15 %) und wirkt, als vermiede es die Person, die gerade gesprochen hat oder angesprochen wurde, eher als Artefakt der Formulierung als ein Verhalten, das man erklären will. Die Rolleneffekte von V1 und V2 liegen sonst nahe beieinander; die Entscheidung zwischen beiden ist knapp, V2 ist kein schlechter Ersatz.
- Gemischte Reihenfolge, weil ohne sie der Listenplatz mit entscheidet (Original: Argmax ist in 32 % der Fälle der zuerst Gelistete, bei 25 % Zufall).

## Grenzen

- **Off-policy:** Die Zustände stammen aus Läufen mit Lunas Orchestrator. In einem echten Jev-Lauf sähe Jev seine eigenen Entscheidungen im Verlauf; ob V1 live einen Rich-get-richer-Kreislauf vermeidet, zeigt erst ein Pilot (Sprecherwiederholung, Entropie, Turn-Anteil der Fokuspersona je Lauf).
- **Zwei Unterschiede zu V0:** Der Fragetext und der Satz zum Wiederholen („if that is factually correct“ entfällt). Das ist nicht getrennt. Ein V1 mit dem alten Schlusssatz würde das trennen.
- Long-Term-Memory leer, nur vier Zellen je Statusbedingung, Intervalle gelten innerhalb dieser Zellen, nicht für weitere Themen.
- Stabilität unter 90 % heißt: Reihenfolge und Rollen verändern die Wahl in 15–20 % der Zustände. Jev ist damit kein neutraler Selektor, sondern ein reproduzierbarer mit bekannter Sensitivität.
- Eine geänderte Frage macht Jev zu einem anderen Selektor. V0 bleibt als dokumentierter Vergleich; die verbesserte Variante muss eine eigene, benannte Variante sein (kein Überschreiben von `JevSpeakerSelector::DECISION`), und die schon gelaufenen Jev-Orchestrator-Zellen (V0, Listenreihenfolge des Laufs) bleiben eine eigene Serie.
- Das gemischte Jev-State-Layout weicht vom Team-Orchestrator ab (Teilnehmerliste gemischt); die Tests, die „Jev-State = Team-State“ pinnen, müssten dafür angepasst werden.

## Kosten

Hochrechnung dafür, was es spart, wenn Jev statt des Team-Modells (Luna) die Auswahl trifft. Grundlage sind die echten `prompt_logs` der 720-Turn-Serien je Pipeline (12 Zellen × 60 Turns, nur Luna-Zellen, ohne Jev-Zellen).

**Annahme:** Luna läuft über das Gateway auf einem Abo-Login, es gibt keinen Listenpreis. Als Ersatz dienen die Listenpreise von gpt-5-mini (0,25 $ pro Million Eingabe-Token, 2,00 $ pro Million Ausgabe-Token; Reasoning wird als Ausgabe gerechnet, Cache-Rabatte bleiben unberücksichtigt). Die Dollarbeträge sind also ein Maßstab, keine Rechnung; die Token-Anteile stehen daneben und hängen nicht vom Preis ab. Ein Jev-Call kostet nach dem ersten Replay etwa 0,00002 $.

| Pipeline | Token gesamt (ein / aus) | Anteil der Auswahl-Calls an den Token | geschätzte Kosten gesamt | davon Auswahl | mit Jev |
|---|---|---|---|---|---|
| Orchestrator | 12,6 M / 0,41 M | 31 % | 3,97 $ | 1,18 $ | 0,01 $ |
| Quality | 22,5 M / 1,09 M | 17 % | 7,80 $ | 1,17 $ | 0,01 $ |
| Blind Quality | 22,3 M / 1,11 M | 16 % | 7,80 $ | 1,11 $ | 0,01 $ |
| Score | 18,9 M / 0,60 M | 0 % | 5,92 $ | – | +0,01 $ |

- Ersparnis: **30 % bei Orchestrator, 14–15 % bei Quality und Blind Quality, 0 % bei Score**, zusammen etwa **13–14 %** der vier Serien (3,47 $ von 25,49 $ je 720 Turns und Pipeline). Round Robin hat keine Modellentscheidung und ist nicht eingerechnet.
- Bei Score bleibt alles beim Team-Modell, weil die Agenten weiter Gedanke und Priorität schreiben und Jev nur die Zahl liefert; dort kommen vier Jev-Calls pro Turn dazu.
- Der große Posten ist bei Quality und Score nicht die Auswahl, sondern dass alle vier Agenten jeden Turn denken (65 % der Eingabe-Token bei Quality, 79 % bei Score). Daran ändert Jev nichts.
- Absolut ist das wenig (rund 3,5 $ je vier Serien à 720 Turns, mit dem Ersatzpreis). Der Hauptgrund für Jev ist die Unabhängigkeit vom Team-Modell, nicht der Preis.
- Dazu käme Zeit: Ein Jev-Call dauert deutlich weniger als ein Luna-Call mit Reasoning (im Replay gut 0,5 s je Call einschließlich 150 ms Pause, vier Worker parallel; die Latenz je Turn wurde nicht gemessen).
