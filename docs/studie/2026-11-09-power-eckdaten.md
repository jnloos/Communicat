# Eckdaten aus den Pilotläufen für die Power-Rechnung (09.11.2026)

Skript: `2026-11-09-power-eckdaten.py`. Alle Zahlen stehen in `2026-11-09-power-eckdaten.json`, ein Lauf pro Zeile in `2026-11-09-power-eckdaten-runs.csv`.
Datenbasis: eine Kopie der Testdatenbank (`database/database.sqlite`, per `VACUUM INTO`), Stand 09.11.2026, 22:20 UTC. Die Gender-Luna-Serie (485–580) ist darin vollständig.
Neu rechnen: `python3 -I docs/studie/2026-11-09-power-eckdaten.py <kopie.sqlite> docs/studie/2026-11-09-power-eckdaten`

## Zielgröße und Filter

- **Zielgröße:** Turn-Anteil der Fokus-Persona in den ersten 30 gesprochenen Turns, in Prozentpunkten. Fokus-Persona ist das Expert mit dem Persona-Tag `focus persona: true` (Tag-Wert 9).
- **Gesprochen** heißt `job_logs.words IS NOT NULL AND expert_id IS NOT NULL`, nach Turn-Index sortiert, die ersten 30. Das ist dieselbe Regel wie in `SpokenTurns`.
- **Nur Dreiergruppen:** genau 3 Experts in `project_contributors`.
- **Ausgeschlossen und gezählt:**
  - weniger als 30 gesprochene Turns: 0 Läufe
  - keine eindeutige Fokus-Persona: 0 Läufe
  - ein gescheiterter Turn zwischen dem ersten und dem 30. gesprochenen Turn, dessen Fehler kein Transportfehler ist (Ablehnung, HTTP 4xx, unlesbare Antwort): 0 Läufe
- **Nicht ausgeschlossen, nur gezählt:** Turns, die am Transport scheiterten. In `job_logs.error` steht dann `ProviderConnectionException`, `ProviderOverloadedException` oder `BroadcastException` (Reverb aus). Das betrifft 15 Läufe. Hatten in so einem Turn schon frühere Aufrufe geklappt, kann ein Gedanke im Kurzzeitgedächtnis überschrieben worden sein. Diesen Rest nehme ich hin.
- **Nicht ausgeschlossen:** gescheiterte Turns vor dem ersten gesprochenen Turn, denn sie hinterlassen nichts im Verlauf. Das betrifft 556: Dort wurde der erste Denk-Prompt einmal vom Inhaltsfilter abgelehnt, ein Fehlalarm.
- **Bedingung** setzt sich aus den Projekt-Tags `status condition`, `gender composition`, `origin composition` und `gender sentence` zusammen, **Thema** ist das zweite Feld des Titels.

Aufgenommen sind **232 Läufe**: 136 auf gpt-5-mini, 96 auf GPT-5.6 Luna über OmniRoute.

## 1. Streuung je Lauf

Die Residual-SD stammt aus einer OLS-Regression `turn_share ~ condition + topic` je Pipeline × Modell, berechnet als sqrt(RSS / (n − Rang)).

| Pipeline | Modell | n | Mittel | SD roh | SD residual | df |
|---|---|---|---|---|---|---|
| Score | gpt-5-mini | 40 | 34,1 | 15,2 | **13,8** | 27 |
| Score | Luna | 32 | 32,9 | 9,0 | **6,1** | 21 |
| Orchestrator | gpt-5-mini | 40 | 27,5 | 21,8 | **19,1** | 27 |
| Orchestrator | Luna | 32 | 34,2 | 3,6 | **3,1** | 21 |
| Quality | gpt-5-mini | 16 | 40,8 | 25,6 | **25,4** | 9 |
| Blind Quality | gpt-5-mini | 40 | 28,7 | 16,5 | **15,4** | 27 |
| Blind Quality | Luna | 32 | 30,4 | 15,7 | **15,2** | 21 |

Gepoolt mit `turn_share ~ condition + pipeline + topic + model`: alle Läufe 15,6 (df 208), nur gpt-5-mini 18,6 (df 120), nur Luna 9,7 (df 83).

**Woher „122 Pilotläufe, SD 19,2 pp“ kommt:** Die Zahl lässt sich aus `2026-10-30-rohdaten-runs.csv` reproduzieren. Es sind alle Dreierläufe darin (Projekte 127–479, nur gpt-5-mini, erste 30 Turns): n = 122, SD = 19,27. Sie ist die **rohe** SD über alle Läufe, gemischt aus Gender (64), Herkunft (48) und Status in Dreiergruppen (10) und über Score 36, Orchestrator 35, Blind Quality 35 und Quality 16. Ein Filter auf Fehler ist nicht eingerechnet, Bedingung und Pipeline sind nicht herausgerechnet. Mit den heutigen Daten ergibt dieselbe Rechnung für gpt-5-mini 19,4 bei n = 136.

**Woher 24,8 kommt** (Förderantrag vom 30.10., §6): Das ist eine andere Größe, nämlich die SD der **Paardifferenzen** (22,6, 53 Freiheitsgrade, gepoolt über Kontraste). 24,8 ist die obere Grenze ihres 80-%-Konfidenzintervalls nach Browne (1995). Für unkorrelierte Paarläufe gilt SD_Differenz ≈ √2 · SD_residual. Aus 16 bis 19 pp residual folgen so 22 bis 27 pp für die Differenz, das passt zu 22,6.

## 2. Bestand

Die vollständige Tabelle Pipeline × Bedingung × Thema × Modell × Sitz steht in `2_inventory` der JSON-Datei. Kurz gefasst: Fast jede Zelle hat genau einen Lauf, alle mit Seed 42. Pro Bedingung und Pipeline ergibt das 4 Läufe, einen pro Thema. Bei gpt-5-mini gibt es Gender, Herkunft und Status. Bei Luna gibt es nur Gender, jeweils ohne und mit dem Satz „You are a woman/man.“. Status in Dreiergruppen gibt es nur auf gpt-5-mini.

## 3. Szenarien und Namen

Gerechnet auf den Residuen nach Bedingung und Modell, je Pipeline: die SD der Mittelwerte je Stufe und ICC(1) aus einer einfaktoriellen ANOVA mit n0-Korrektur. Negative Schätzungen bleiben stehen; sie bedeuten einen Anteil von etwa 0.

| Pipeline | n | Thema: SD der Mittel | ICC Thema | Name: Stufen | Name: SD der Mittel | ICC Name |
|---|---|---|---|---|---|---|
| Score | 72 | 3,3 | 0,06 | 13 | 3,9 | −0,10 |
| Orchestrator | 72 | 7,3 | 0,19 | 13 | 9,6 | −0,02 |
| Quality | 16 | 11,8 | 0,04 | 8 | 17,7 | 0,23 |
| Blind Quality | 72 | 1,9 | −0,03 | 13 | 8,3 | 0,16 |

Bei den Namen ist Vorsicht geboten: Die meisten Namen haben nur 2 bis 6 Läufe, und ein Name trägt 15 bis 16 Läufe. Die SD der Namensmittel steckt deshalb zum großen Teil voller Lauf-Rauschen. Aussagekräftiger ist der ICC, und der liegt bei 0 bis 0,23.

## 4. Mittlerer Turn-Anteil je Bedingung

Je Pipeline × Modell × Bedingung mit n, Mittelwert und SD steht alles in `4_condition_means`. Mit 3 Teilnehmenden liegt der faire Anteil bei 33,3. Jede Zelle hat n = 4.

**gpt-5-mini:**
- **Status** pos vs. eq: Score 52,5 vs. 29,2 (+23), Blind Quality 55,0 vs. 30,8 (+24), Orchestrator 29,2 vs. 39,2 (−10)
- **Gender**, einziger Mann vs. lauter Frauen: Score +14, Orchestrator +8, Quality +23, Blind Quality +3. Einzige Frau vs. lauter Männer: Score +8, Orchestrator −6, Blind Quality −15
- **Herkunft**, einzige nigerianische Person vs. lauter Deutsche: Score −4, Orchestrator +4, Blind Quality +3

**Luna, Gender ohne / mit Satz** (Differenz in pp):

| Pipeline | einziger Mann: ohne Satz | einziger Mann: mit Satz | einzige Frau: ohne Satz | einzige Frau: mit Satz |
|---|---|---|---|---|
| Score | −1 | +10 | +4 | 0 |
| Orchestrator | +1 | −3 | 0 | 0 |
| Blind Quality | −5 | +8 | +16 | −3 |

Effekte von 10 pp und mehr zeigen sich beim Status und bei gpt-5-mini teils beim Gender. Bei der Herkunft zeigen sie sich nicht. Bei n = 4 je Gruppe sind das Richtungen, keine Schätzungen.

## 5. Token je Lauf

Gezählt sind die erfolgreichen `prompt_logs` (`status = 'ok'`) bis zum 30. gesprochenen Turn. Eine Summe gibt es nur, wenn jeder dieser Aufrufe die Zahl gemeldet hat; `n` je Spalte zeigt, wie viele Läufe bleiben.

| Pipeline | Modell | In Mittel | In Median | Cached Mittel | Out Mittel | Out Median |
|---|---|---|---|---|---|---|
| Score | gpt-5-mini | 795.384 | 786.196 | 573.664 (n 24) | 51.199 | 50.746 |
| Score | Luna | 409.964 | 408.471 | – | 19.206 | 19.076 |
| Orchestrator | gpt-5-mini | 567.609 | 584.364 | 456.912 (n 24) | 29.870 | 29.960 |
| Orchestrator | Luna | 322.314 | 320.626 | – | 15.359 | 15.446 |
| Quality | gpt-5-mini | 988.486 | 981.726 | – | 75.432 | 74.320 |
| Blind Quality | gpt-5-mini | 950.207 | 957.116 | 686.029 | 74.165 | 73.548 |
| Blind Quality | Luna | 477.242 | 481.704 | – | 33.712 | 33.662 |

Die OmniRoute-Route meldet keine gecachten Token, deshalb bleibt die Spalte bei Luna leer und bedeutet nicht 0. Bei gpt-5-mini haben ältere Läufe noch keine gecachten Token erfasst.

## Vorbehalte

- Fast alles läuft auf Seed 42 mit einem Lauf pro Zelle. Die Residual-SD enthält deshalb Lauf-Rauschen und Wechselwirkungen, etwa Bedingung × Thema.
- Die Freiheitsgrade je Zelle sind klein (9 bis 27). Für die Planung sollte man wie am 30.10. die obere Konfidenzgrenze nehmen.
- Luna streut in Orchestrator und Score viel weniger als gpt-5-mini (3 bis 6 pp statt 14 bis 19 pp), in Blind Quality gleich stark. Die Wahl des Modells ändert die benötigte Laufzahl stark.
