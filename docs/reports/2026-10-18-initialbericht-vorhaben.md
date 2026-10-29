# Initialbericht: Statuseffekte auf den Redeanteil in Multi-Agenten-LLM-Diskussionen

**Architektur oder Modell?** · Jan-Niclas Loosen, Universität Trier · **Stand:** 18.10.2026

Dieser Bericht erklärt das Vorhaben von Grund auf: die Frage, warum sie offen ist, das Versuchsdesign, die
Messung, die Plattform, die dafür gebaut wurde, und wo die Arbeit heute steht. Er stützt sich auf die
*Vorhabensübersicht* und die *Relevanz-Einschätzung* (beide 16.09.2026, im Repo unter `docs/plans/`, Quellenarchiv:
126 Quellen, davon 115 im Volltext geprüft) und auf den Stand des Codes. Die bisherigen Ergebnisse stehen im
*Zwischenbericht vom 18.10.2026* (`docs/reports/2026-10-18-zwischenbericht-statuseffekt.md`).

---

## 1. Das Vorhaben in einem Satz

Untersucht wird, ob ein Status-Titel wie „CEO“ den Redeanteil eines LLM-Agenten in einer Gruppendiskussion
erhöht, wie es für Menschen gut belegt ist, und ob die Stärke dieses Effekts eher vom
**Sprecherwahl-Mechanismus** (Wer bekommt als Nächstes das Wort?) oder vom **verwendeten Modell** abhängt.

Ein Nullergebnis oder ein umgekehrter Effekt ist ausdrücklich ein gültiges Ergebnis. „LLM-Agenten
reproduzieren den menschlichen Statuseffekt nicht“ und „die Architektur verändert ihn nicht“ sind
eigenständige Befunde.

## 2. Warum die Frage offen ist

**Bei Menschen** ist der Zusammenhang theoretisch und empirisch gut gestützt. Die *Status Characteristics Theory*
(Berger1972) sagt voraus, dass äußere Statusmerkmale über Leistungserwartungen steuern, wer sich beteiligt,
auch wenn das Merkmal für die Aufgabe irrelevant ist. Eine Meta-Analyse (SchmidMast2002) bestätigt den
Zusammenhang von Dominanz und Sprechzeit, stärker bei zugewiesenen Rollen. Auch in führungslosen Gruppen
entsteht rasch eine Hierarchie der Beteiligung (Bales1951). Ungleichheit ist der Normalfall. Der richtige
Vergleichspunkt ist deshalb die Gruppe *ohne* Titel, nicht die Gleichverteilung.

**Bei LLMs** sind Statuseffekte nachgewiesen, fast immer aber auf Konformität, Deferenz und Beharren, kaum je auf
den Redeanteil. Nur zwei Arbeiten messen etwas, das dem Redeanteil entspricht, jeweils unter einem einzigen
Mechanismus und als Nebenanalyse:

- **Tak2026:** Ein Agent mit dem Label „instructor“ bekommt unter Selbstmeldung per Priorität nur 14,5 % des
  Rederechts statt der erwarteten 33 %. Der Scheduler gleicht die Beteiligung aber standardmäßig aus, was einen
  Teil der fehlenden Dominanz erklären kann.
- **Deng2025:** Ein Leader schreibt unter fester Reihenfolge (Round Robin, nur GPT-4o) in allen vier
  Prompt-Varianten mehr Wörter, signifikant ist das aber nur in einer.

Beide Arbeiten sprechen gegen eine einfache Übertragung des menschlichen Musters. Daraus ergibt sich die Lücke:

> Kein Paper misst Turn-Anteil *und* Gesprächsanteil gemeinsam unter einer Status-Manipulation, über mehrere
> Sprecherwahl-Mechanismen und über mehrere Modelle.

Genauer sind drei Stellen vollständig offen: (1) beide Einheiten zusammen, ob ein betitelter Agent also
seltener, aber länger spricht; (2) Status × Mechanismus, denn Tak2026 nennt den Raum der Floor-Control-Mechanismen
selbst „largely unexplored“; (3) Status × Modell.

## 3. Hypothesen

| | Hypothese | Erwartung |
|---|---|---|
| **H1** | **Statuseffekt.** Unter freier Sprecherwahl weichen Turn-Anteil und Gesprächsanteil des betitelten Agenten von denen desselben Agenten ohne Titel ab. | Nach der Theorie nach oben. Unter Round Robin höchstens im Gesprächsanteil. |
| **H2** | **Architektur moderiert.** Die Größe des Effekts unterscheidet sich zwischen den Mechanismen. | Am größten, wo der Titel direkt in die Auswahl eingeht (Orchestrator, Selbst-Score), am kleinsten unter Round Robin. |
| **H3** | **Modell moderiert.** Die Größe des Effekts unterscheidet sich zwischen den Modellfamilien. | Ob der Modell- oder der Mechanismusanteil an der erklärten Varianz größer ist, bleibt als offene Frage formuliert. |

## 4. Versuchsdesign: eine vollständig gekreuzte Matrix

**4 Sprecherwahl-Mechanismen × 2 Statusbedingungen × 3 Modellfamilien = 24 Zellen.** Jede Pipeline läuft einmal mit
und einmal ohne Status, für jedes Modell.

- Der **Statuseffekt (H1)** ist die Differenz „mit“ minus „ohne“ in derselben Zeile.
- Die **Architektur (H2)** zeigt sich im Unterschied dieser Differenzen zwischen den Zeilen.
- Das **Modell (H3)** zeigt sich im Unterschied dieser Differenzen zwischen den Modellen.

### 4.1 Die vier Mechanismen

Sie unterscheiden sich **nur** darin, wie das Wort vergeben wird. Alles andere (Prompts, Gedächtnis, Messung) ist
in allen Zellen identisch.

| Mechanismus | Wie das Wort vergeben wird | Wie der Status wirken kann |
|---|---|---|
| **Round Robin** | Feste Rotation nach Sitz. Nullpunkt für den Turn-Anteil, Replikation von Deng2025. | Nur über die Länge der Beiträge. |
| **Select by score** | Jeder Agent meldet pro Zug eine Sprechpriorität von 1 bis 5, die höchste spricht. | Der Titel hebt die eigene Priorität. Selbstbewertung ist als Kriterium unzuverlässig. |
| **Orchestrator-Selection** | Ein LLM wählt anhand des Verlaufs den nächsten Sprecher (wie der GroupChatManager in AutoGen). | Der Selektor kann den Titel bevorzugen. Seine Auswahl ist selbst modellabhängig verzerrt. |
| **Select by quality of proposal** | Alle Agenten schreiben einen Entwurf, ein Richtermodell wählt einen davon und vergibt so das Wort. | Der Titel wirkt über die Sichtbarkeit beim Richter oder über den Inhalt der Entwürfe. |

**Keine ausgleichende Logik.** In keinem Mechanismus gibt es Balancierung. Gleichstände werden zufällig, aber
reproduzierbar entschieden, nie zugunsten seltener Sprecher. Der gleiche Agent darf beliebig oft hintereinander
sprechen, diese Wiederholung *ist* die Messung. Eine eingebaute Balancierung würde einen Statuseffekt durch die
Architektur selbst glätten.

### 4.2 Die Statusbedingungen

- **Mit Status:** Ein Agent trägt einen höheren Titel (im Plan „CEO“), die übrigen gleichrangige Rollennamen.
  Alle bekommen dieselben Aufgabeninformationen.
- **Ohne Status:** Im Plan erhält derselbe Agent einen neutralen Zusatztext gleicher Länge statt des Titels
  (Qu2026). **Umsetzung bisher:** ein Tausch des Rollenetiketts. Jede Persona existiert doppelt mit identischer
  Beschreibung, nur das Etikett unterscheidet sich (z. B. *Teacher* gegen *Student* in einer Gruppe mit drei
  Studenten). Das ist strenger als der Zusatztext, weicht aber vom Plan ab und braucht eine Begründung im
  Methodenteil.
- **Rotation:** Der betitelte Agent soll über alle Positionen der Gruppe wechseln, weil Position und
  Erstsprecherrolle eigene Effekte haben (Anderson2009).

### 4.3 Gruppen, Themen, Modelle

- **Vier Agenten je Gruppe**, anschlussfähig an Deng2025, feste Größe, damit Ungleichheitsmaße vergleichbar
  bleiben.
- **Offene Entscheidungsfragen** (bisher Handyverbot an Schulen, Bürotage, Wahlalter 16, Schonung von
  Stammspielern) mit festem Turn-Budget je Lauf.
- **Drei Modellfamilien**, innerhalb eines Laufs homogen besetzt. Gemischte Teams würden Fähigkeit mit Status
  vermengen.
  **Stand 18.10.2026:** Der Betreuer möchte das Modell festsetzen. Welches Modell das ist und ob es bei drei
  Familien bleibt, ist offen. Davon hängt H3 ab.
- **Selektor und Richter** laufen auf dem Modell des Teams, ihr Bias gehört damit zum Modellfaktor. Eine kleine
  Gegenprobe mit festem Selektor soll zeigen, wie viel des Modelleffekts am Selektor liegt.
- Der Vergleich findet in **Gruppenchats** statt. In Multi-Agent-Debate sprechen alle Agenten gleichzeitig, der
  Redeanteil ist dort konstruktionsbedingt konstant.

## 5. Messung

Der Redeanteil wird in **zwei Einheiten** gemessen, die auseinanderlaufen können:

- **Turn-Anteil:** wie oft ein Agent das Wort bekommt.
- **Gesprächsanteil:** wie viel Text er beiträgt, in **Wörtern und Zeichen, nie in Modell-Tokens** (die
  Tokenizer der Modellfamilien unterscheiden sich).

Gezählt werden nur öffentliche Chat-Beiträge. Reasoning sowie Nachrichten von Selektor und Richter zählen nicht.

| Kennzahl | Wozu |
|---|---|
| Turn-Anteil des betitelten Agenten | erste Zielgröße für H1 bis H3 |
| Gesprächsanteil in Wörtern (und Zeichen als Robustheitsprüfung) | zweite Zielgröße, unter Round Robin die einzige |
| Verhältnis Gesprächs- zu Turn-Anteil | zeigt, ob ein Agent seltener, aber länger spricht |
| Entropie und Gini der Turn-Verteilung je Lauf | Ungleichheit auf Gruppenebene |
| Priorität, Selektionsrate, Richterwahl je Agent | zeigt, über welchen Weg der Titel wirkt |
| Übernahme der Position des betitelten Agenten | Nebenzielgröße, trennt Rederecht von Einfluss |

**Analyse:** gemischte Modelle mit dem Anteil des betitelten Agenten als abhängiger Variable, den
Wechselwirkungen Status × Pipeline und Status × Modell sowie Thema und Lauf als zufälligen Effekten. Dazu eine
Zerlegung der erklärten Varianz nach Pipeline, Modell und Wechselwirkung (nach Hu2026).

## 6. Kontrollen

- Neutraler Zusatztext (bzw. Etikett-Tausch) in der Bedingung ohne Status.
- Grundneigung zu sprechen je Modell aus den Läufen ohne Status, weil sie stark modellabhängig ist.
- Homogene Teams, damit sich Titel und Fähigkeit nicht vermengen.
- Rotation des Titels über alle Positionen.
- **Emergente Hierarchie:** Die Verteilung ohne Status wird selbst ausgewertet, da Ungleichheit auch ohne Titel
  entsteht.
- Vollständige Modellkonfiguration berichten (Checkpoint, Reasoning-Modus, Temperatur, Token-Limit,
  System-Prompt).
- Ausfallraten je Modell berichten statt stillschweigend zu filtern.

## 7. Nutzerstudie (geplant)

Sie klärt, ob die entstehenden Verteilungen Menschen stören. Material sind Diskussionen aus der Matrix. Der Inhalt
bleibt möglichst gleich, variiert wird die Verteilung: ausgewogen, dominant mit Titel, dominant ohne Titel. So
lässt sich trennen, ob der Titel oder die Dominanz das Urteil treibt. Gemessen werden wahrgenommene Dominanz,
Vertrauen, Qualität des Ergebnisses und das Gefühl des Ausgeschlossenseins, dazu der Abgleich von Wahrnehmung
und tatsächlicher Verteilung. Die Teilnehmenden lesen mit und greifen nie ein. Dass ausgewogene Redeanteile besser
sind, wird nicht vorausgesetzt.

## 8. Die Plattform: Communicat

Die Studie läuft auf einer eigenen Anwendung (Laravel 12, Livewire 4). Ein Projekt ist ein Thema, bis zu vier
KI-Experten bilden die Gruppe, ein Lauf wird Zug um Zug erzeugt. Menschen lesen mit, sprechen aber nie.

**Eine Pipeline, ein austauschbarer Selektor.** Jeder Turn durchläuft dieselben Stufen: Sprecher wählen, ggf.
denken (privater Kurzzeitgedanke), sprechen, speichern, Verlauf zusammenfassen. Die Mechanismen unterscheiden sich
nur in der Auswahlstufe (`SpeakerSelector`). Neue Selektoren oder Pipelines sind neue Klassen, keine Änderung an
bestehenden.

**Messung im Runner, nicht in den Stufen.** `TurnRunner` misst jeden Turn (Sprecher, Sitz, Auswahlsignale, Wörter,
Zeichen, Reasoning-Tokens, Status, Fehler), damit keine Pipeline die Messung auslassen kann und auch gescheiterte
Turns gezählt werden. Ein Turn gilt als *gesprochen*, wenn er einen öffentlichen Beitrag erzeugt hat, unabhängig
vom späteren Status.

**Gedächtnis in drei Schichten** (nach Nonomura et al. 2025): gemeinsamer Verlauf mit Adressat je Beitrag,
privater Kurzzeitgedanke je Experte, laufende Langzeit-Zusammenfassung.

**Reproduzierbarkeit.** Jede Konfiguration (Pipeline, Modell, Prompts, Seed) wird beim ersten Turn eingefroren.
Gleichstände ziehen aus Seed und Turn-Index. Es gibt kein Failover zwischen Anbietern: Ein Lauf bleibt in einer
Modellfamilie. Alle Modellaufrufe (Prompt, Antwort, Tokens, Latenz, Fehler) werden protokolliert.

**Unlesbare Antworten beenden keinen Lauf.** Jeder Selektor hat einen Fallback und vermerkt ihn im Log, damit die
Auswertung die sauberen Turns von den Fallbacks trennen kann.

**Drumherum:** Headless-Läufe über die Konsole (`project:create`, `project:run`, `project:metrics`), eine
Statistikseite, die Fragen wie „Turn-Anteil des Titelträgers je Pipeline, aufgeschlüsselt nach Thema“ beantwortet,
Export aller Läufe als CSV und als Bundle, Anbieter für OpenAI, Anthropic und Gemini.

## 9. Abgrenzung zu den nächsten Nachbarn

| Arbeit | Status variiert? | Redeanteil gemessen? | Offene Stelle |
|---|---|---|---|
| Tak2026 | ja, Label „instructor“ | ja, Rederecht, nebenbei | ein Mechanismus mit ausgleichendem Scheduler, kleine Modelle |
| Deng2025 | ja, Leader | Wortzahl | Round Robin fixiert die Turns, nur GPT-4o |
| Choi2026, Barkett2026, Qu2026 | ja | nein | Konformität bzw. Deferenz, feste Abläufe |
| Nonomura2025, Marandi2026 | nein | nein | vergleichen Mechanismen, aber nicht auf Redeanteil unter Status |
| Hu2026 | nein | nein | Varianzzerlegung Struktur × Modell, aber auf Kreativität |

## 10. Risiken

- **Der Effekt bleibt aus oder kehrt sich um** (Tak2026, Deng2025). Berichtenswert, sofern Kontrolle und Teststärke
  stimmen. Die Läufe je Zelle legt ein Pilot per Poweranalyse fest.
- **Selbstbewertung versagt als Auswahlkriterium.** Die Schwäche gehört zur Pipeline und wird protokolliert,
  nicht repariert.
- **Richter- und Selektor-Biases überlagern den Titel.** Dagegen stehen die pipelineinternen Messgrößen und die
  Gegenprobe mit festem Selektor.
- **Aufwand:** 24 Zellen, dazu Rotation, Themen und Wiederholungen. Kosten und Laufzeit werden als eigene Größe
  berichtet.
- **Trainierbarkeit:** Verhalten ist per Training stärker veränderbar als per Architektur. Gehört ins
  Limitationskapitel.

## 11. Arbeitspakete und Stand

| # | Arbeitspaket | Stand 18.10.2026 |
|---|---|---|
| 1 | Pipeline mit austauschbarem Selektor, Persona-Schicht, Protokollierung | Pipeline, Selektor und Protokollierung fertig. **Persona-Schicht** (Titel, neutraler Zusatz, Rotation über Sitze) **offen** |
| 2 | Auswertungsskript, an Extremfällen geprüft | Anteile, Gini, Trajektorien und Statistikseite vorhanden. **Mixed Models, Entropie, Varianzzerlegung, Übernahme der Position offen** |
| 3 | Die übrigen drei Selektoren | **fertig** (Score, Orchestrator, Quality, dazu eine blinde Quality-Variante) |
| 4 | Pilot, Powerplanung | Pilotläufe mit je einem Lauf pro Zelle auf einem Modell gelaufen, Chatlänge begründet (100 Turns). **Poweranalyse (G\*Power) offen** |
| 5 | Matrix vollständig fahren | **offen**, braucht `RunExperiment` (Matrix-Runner) und die Persona-Schicht |
| 6 | Analyse mit Wechselwirkungen, Gegenprobe mit festem Selektor | **offen** |
| 7 | Nutzerstudie vorbereiten und durchführen | **offen** |
| 8 | Auswertung und Schreiben | **offen** |

**Erste Ergebnisse (Details im Zwischenbericht):** Auf gpt-5-mini zeigt sich unter freier Sprecherwahl in 29 von
32 Paarvergleichen ein höherer Redeanteil für die Persona mit Titel. Der Effekt hängt vom Mechanismus ab (am
stärksten unter Quality, schwächer und sitzabhängig unter dem Orchestrator) und lässt sich nicht allein auf die
Sichtbarkeit des Titels im Richter-Prompt zurückführen. Ein Agent dominiert fast immer auch ohne Titel: Der Titel
erzeugt die Ungleichheit nicht, er bestimmt, auf wen sie fällt. Alles ist bisher ein Lauf pro Zelle, ein Modell,
ein Seed und deskriptiv. Der Modellvergleich, der Kern von H3, steht aus.

## 12. Glossar

- **Turn:** ein Zug in der Diskussion, in dem ein Agent einen öffentlichen Beitrag schreibt.
- **Selektor:** die Komponente, die den nächsten Sprecher bestimmt. Der einzige Teil der Pipeline, der zwischen
  den Mechanismen wechselt.
- **Zelle:** eine Kombination aus Pipeline, Statusbedingung, Modell (und Thema).
- **Titelträger / Fokuspersona:** der Agent, dessen Redeanteil gemessen wird.
- **Turn-Anteil / Gesprächsanteil:** Anteil an den Wortmeldungen bzw. am Textumfang (Wörter, Zeichen).
- **Gini:** Ungleichheitsmaß der Turn-Verteilung in einer Gruppe, bei vier Agenten höchstens 0,75.
- **Fallback:** Ersatzentscheidung eines Selektors, wenn die Modellantwort nicht lesbar war, im Log vermerkt.
