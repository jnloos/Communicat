# Die drei fehlenden Sprecherwahl-Pipelines: Design

Stand: 24. September 2026. Branch: `Studie-JanNiclas-Loosen`.
Vorgänger: `docs/superpowers/specs/2026-09-20-generische-turn-pipeline-design.md` (Abschnitt 3.2
entwarf die vier Pipelines) und `docs/superpowers/specs/2026-09-24-laravel-ai-sdk-migration-design.md`
(die LLM-Schicht, auf der diese Arbeit aufsetzt).
Fachlicher Rahmen: `docs/plans/vorhabensuebersicht.pdf`.

## 1. Ziel

Die Studie kreuzt vier Sprecherwahl-Mechanismen mit zwei Statusbedingungen und drei Modellfamilien.
Gebaut ist bisher einer: Round Robin, der Nullpunkt für den Redeanteil. Diese Arbeit ergänzt die
drei anderen:

- **select by score** — jeder Agent bietet eine Priorität von 1 bis 5, der höchste spricht.
- **orchestrator selection** — ein Modellaufruf wählt den nächsten Sprecher aus dem Verlauf
  (nach AutoGens GroupChatManager).
- **select by quality of proposal** — alle Agenten entwerfen einen Beitrag, ein Judge bewertet sie
  und vergibt das Rederecht.

Leitplanke bleibt: alles außer dem Selector ist zwischen den Zellen identisch. Kein Mechanismus
gleicht Beteiligung aus.

## 2. Getroffene Entscheidungen

| Thema | Entscheidung |
|---|---|
| Anweisungen an den Sprecher | Nein. Der Orchestrator wählt nur, er steuert nicht |
| Unlesbare Modellantwort | Fallback plus Protokoll, nie ein Abbruch des Laufs |
| Derselbe Sprecher zweimal in Folge | Erlaubt, ohne Obergrenze |
| Gewinner bei Quality | Ergibt sich aus den Punkten des Judge, der Judge nennt keinen Gewinner |
| Ausgabeformat | Marker in Blade-Prompts wie bisher, kein `HasStructuredOutput` |
| Gleichstände | `TieBreaker`, deterministisch aus `projects.seed` und `turnIndex` |
| Zweiter Speak bei Quality | Ja — der Gewinner schreibt mit dem Speak-Prompt, sein Entwurf geht als Kontext hinein |
| Judge-Aufrufe im Log | Eigener `Purpose::Judge`, damit sie von der Sprecherwahl unterscheidbar sind |
| Datenbank | Keine Migration. Alle Signale gehen nach `job_logs.selection` |
| Persona/Status und `RunExperiment` | Nicht Teil dieser Arbeit |

## 3. Was der Bestand schon hergibt

Geprüft, nicht angenommen:

- `SpeakerSelector::select(TurnPayload): Selection` ist der Strategie-Seam, `SelectSpeaker::with()`
  die Verdrahtung. `PipelineRegistry` findet neue Pipelines über den Ordner-Scan, ohne Registrierung.
- `Selection` trägt bereits `signals`, `reasoning` und `tieBroken` und serialisiert sie nach
  `job_logs.selection`.
- `Thought` trägt bereits `priority` und `proposal`.
- `Speak` reicht `$payload->thoughtOf($speaker)?->proposal` schon in seinen Prompt — die
  Quality-Pipeline braucht dort keine Änderung.
- `Thinking::ask()` fragt beliebig viele Experten gleichzeitig über `ParallelPrompts`.
  `Thinking::section()` schneidet an einem Marker und ist mit `PRIORITÄT:` als Fixture getestet.
- `Purpose::Select` existiert und wird von nichts benutzt.
- `projects.seed` wird in `Project::booted()` erzeugt und nirgends gelesen.

## 4. Aufbau

### 4.1 Zwei neue Think-Stages

`App\Discussion\Stages\ThinkAndPrioritize` und `ThinkAndPropose`. Beide fragen **alle** beitragenden
Experten über `Thinking::ask()`, also parallel, und schreiben wie `ThinkAsSpeaker` das
Kurzzeitgedächtnis jedes Agenten fort.

- `ThinkAndPrioritize` rendert `prompts.think.prioritize` und parst `GEDANKE:` sowie `PRIORITÄT:`.
  Die Priorität ist eine Ganzzahl von 1 bis 5; alles andere ist ein Fallback (Abschnitt 4.8).
- `ThinkAndPropose` rendert `prompts.think.propose` und parst `GEDANKE:` sowie `ENTWURF:`.

`MARKER_THOUGHT` zieht von `ThinkAsSpeaker` nach `Thinking`, weil ihn künftig drei Stages teilen.
Die stage-eigenen Marker (`MARKER_PRIORITY`, `MARKER_DRAFT`) bleiben Konstanten der jeweiligen Stage
und werden wie bisher als Variable in den View gereicht, damit View und Parser nicht auseinanderlaufen.

Dass in Score und Quality alle vier Agenten jeden Turn denken, in Round Robin und Orchestrator nur
der Sprecher, ist ein Architekturunterschied der Mechanismen und gehört in die Limitationen: das
Kurzzeitgedächtnis entwickelt sich in den Zellen unterschiedlich.

### 4.2 Die drei Selektoren

**`HighestBidSelector`** ruft kein Modell. Er liest die Gebote aus `$payload->thoughts()`, nimmt das
höchste und gibt bei Gleichstand an den `TieBreaker` ab. Die Sprecherwahl bleibt damit reine
Auswertung; der Modellaufruf gehört der Think-Stage. Fehlt jedes Gebot, entscheidet der TieBreaker
unter allen Kandidaten.

**`OrchestratorSelector`** macht genau einen Modellaufruf über einen `SelectorAgent`. Er sieht
History und Long-Term, niemals die privaten Gedanken. Der Prompt verlangt `SPRECHER: E{id}` und
`BEGRÜNDUNG:`; die Begründung landet in `Selection::$reasoning`. Ein Token, das
`Project::contributorByPromptId()` nicht auf einen beitragenden Experten auflöst, ist ein Fallback.

**`JudgeSelector`** macht genau einen Modellaufruf über einen `JudgeAgent`. Er sieht die Entwürfe mit
Absender, dazu History und Long-Term. Der Prompt verlangt je Entwurf eine Zeile
`BEWERTUNG: E{id} {punkte}` und eine `BEGRÜNDUNG:`. Die Skala ist 1 bis 10 und damit feiner als die
Gebotsskala 1 bis 5: die 1–5 gibt das Vorhaben für die Priorität vor, für die Qualitätsbewertung ist
keine Skala vorgegeben, und eine feinere erzeugt seltener Gleichstände, die sonst der TieBreaker
auflösen müsste. **Den Gewinner bestimmt der Selector
aus den Punkten, nicht das Modell** — so kann die Antwort nicht in sich widersprüchlich sein
("Gewinner E7, aber E9 hat mehr Punkte"), und die Auswahlregel ist dieselbe wie bei den Geboten.

Beide Modell-Selektoren bekommen `PromptRenderer` und `Memory` per Konstruktor; sie werden über
`app($selectorClass)` aufgelöst und sind damit container-fähig.

### 4.3 TieBreaker

`App\Discussion\Support\TieBreaker::pick(array $candidates, Project $project, int $turnIndex): Expert`.
Deterministisch: der Seed des Projekts und der Turn-Index bilden zusammen den Zufallsstrom, sodass ein
Lauf reproduzierbar ist, ohne dass irgendein Sprecher bevorzugt wird. Jeder aufgelöste Gleichstand
setzt `Selection::$tieBroken`. Damit bekommt `projects.seed` endlich seine Aufgabe.

### 4.4 Zwei neue Agenten

`SelectorAgent` (`Purpose::Select`) und `JudgeAgent` (`Purpose::Judge`) erben von `StudyAgent` und
sind je vier Zeilen — sie geben nur ihren `Purpose` zurück. Alles andere (Instructions, `maxTokens`, `temperature`, `providerOptions`, `ask()`,
die Garantie genau eines Anbieters) erben sie, und `RecordPromptLog` erfasst sie ohne Änderung, weil
es auf `instanceof StudyAgent` und `purpose()` aufsetzt.

`Purpose` bekommt einen Fall `Judge` mit dem Wert `'judge'`. Rein additiv; ohne ihn stünden
Orchestrator- und Judge-Aufrufe beide als `'select'` in `prompt_logs` und wären nur über den Umweg
`job_logs.selection` zu trennen.

### 4.5 `Thinking` bleibt unverändert

Naheliegend wäre, `Thinking::ask()` einen Parameter für die Agentenklasse zu geben, weil Gebote und
Entwürfe sonst als `purpose: 'think'` in `prompt_logs` stehen — nicht unterscheidbar von einem
gewöhnlichen Think-Turn. Das ist hier bewusst **nicht** nötig: Gebote und Entwürfe *sind* Think-Aufrufe
(der Agent denkt und liefert dabei ein Signal), und welche Stage sie erzeugt hat, sagt
`job_logs.selection.selector` über den `job_log_id`, mit dem jede `prompt_logs`-Zeile ohnehin
verknüpft ist. Ein Join genügt, und die Auswertung arbeitet ohnehin pro Run und Pipeline.

`Thinking` ändert sich damit nur an einer Stelle: `MARKER_THOUGHT` zieht von `ThinkAsSpeaker` hierher,
weil ihn künftig drei Stages teilen.

### 4.6 `ask()` wird der einzige Weg zum Modell

`Promptable::prompt()` ist öffentlich und auf `StudyAgent` nicht überschrieben. Ein Selector, der
versehentlich `prompt($text)` statt `ask($text)` aufruft, bekommt `config('ai.default')` statt des
Projektmodells — ein Lauf mit gemischten Modellfamilien, der in `job_logs`, in
`prompt_logs.provider` und im Failover-Wächter keine Spur hinterlässt. Das ist der gefährlichste
stille Fehler, der nach der Migration offen ist, und diese Arbeit fügt genau die Klassen hinzu, die
ihn auslösen könnten.

`StudyAgent::prompt()` wird deshalb überschrieben und wirft, wenn der übergebene Provider nicht dem
Modell des Agenten entspricht; `ask()` ruft es mit dem richtigen Provider. Ein Test hält das fest.

### 4.7 Prompts

Vier neue Blade-Views, Marker als Stage- bzw. Selector-Konstante in den View gereicht:

```
think/prioritize.blade.php   GEDANKE: / PRIORITÄT:
think/propose.blade.php      GEDANKE: / ENTWURF:
select/orchestrator.blade.php SPRECHER: / BEGRÜNDUNG:
select/judge.blade.php        BEWERTUNG: / BEGRÜNDUNG:
```

Kein `HasStructuredOutput`, obwohl es sich für Gebote und Punkte anbietet: strukturierte Ausgabe
setzt jeder Anbieter anders um und kann die Antwort selbst verändern — bei drei Modellfamilien wäre
das eine Störvariable. Die Marker-Konvention ist im Projekt etabliert und über alle Anbieter gleich.

Die Selektor-Prompts sehen `Memory::sharedFor(Project): MemoryView` — dieselbe History und dasselbe
Long-Term wie die Agenten, aber mit leerem Short-Term. Eine kleine Ergänzung an `Memory`, die
sicherstellt, dass ein Selector die privaten Gedanken nicht sehen kann, statt sich darauf zu
verlassen, dass der View sie nicht ausgibt.

### 4.8 Fallback plus Protokoll

Kein unlesbares Modellergebnis darf einen Lauf beenden. `TurnRunner` gibt bei einer Ausnahme
`stop('failed')` zurück, und das hält die ganze Zelle an — bei vier Agenten pro Turn wäre das eine
vervierfachte Angriffsfläche.

| Fall | Verhalten | Protokoll in `signals` |
|---|---|---|
| Gebot fehlt oder liegt außerhalb 1–5 | zählt als 1 | `fallbacks: [E{id}, …]` |
| Kein Gebot ist lesbar | TieBreaker unter allen Kandidaten | `fallbacks` plus `tie_broken` |
| Orchestrator nennt kein auflösbares Token | TieBreaker | `fallback: true` |
| Judge liefert keine lesbare Bewertung | TieBreaker | `fallback: true` |
| Ein Entwurf fehlt bei Quality | der Agent wird nicht bewertet | `fallbacks: [E{id}]` |

Ein fehlender `GEDANKE:` bleibt dagegen ein `ParseFailure` wie bisher — ohne Gedanken gibt es keinen
Turn, und das ist in `ThinkAsSpeaker` schon so entschieden.

## 5. Die drei Pipelines

```
ScorePipeline         ThinkAndPrioritize → SelectSpeaker(HighestBid)   → Speak → PersistMessage → Summarize
OrchestratorPipeline  SelectSpeaker(Orchestrator) → ThinkAsSpeaker     → Speak → PersistMessage → Summarize
QualityPipeline       ThinkAndPropose    → SelectSpeaker(Judge)        → Speak → PersistMessage → Summarize
```

Je eine Klasse mit einer `stages()`-Methode, dazu ein Label in `lang/{de,en}/pipelines.php`. Bei
Quality schreibt der Gewinner mit dem Speak-Prompt und seinem Entwurf als Kontext: der Entwurf
entstand unter einem anderen Prompt, und die Textlänge ist eine Messgröße — sie muss zwischen allen
vier Zellen aus derselben Quelle kommen.

## 6. Messung

Keine neue Spalte. Alles fließt in `job_logs.selection`, das `Selection::toArray()` schon schreibt:

- **Score:** `signals.bids` je Agent, `signals.fallbacks`, `tie_broken`
- **Orchestrator:** `reasoning`, `signals.fallback`, `tie_broken`
- **Quality:** `signals.scores` je Agent, `signals.fallbacks`, `reasoning`, `tie_broken`

Die Modellaufrufe der Selektoren stehen mit ihrem Purpose in `prompt_logs` und sind damit von den
Beiträgen getrennt — in `words` und `chars` zählt weiter nur die öffentliche Contribution.

## 7. Tests

- Je Selector ein Unit-Test: höchstes Gebot gewinnt, Gleichstand geht an den TieBreaker, jeder
  Fallback aus der Tabelle in 4.8 wird ausgelöst und protokolliert.
- `TieBreaker`: gleicher Seed und gleicher Turn-Index ergeben dieselbe Wahl, ein anderer Turn-Index
  im Allgemeinen eine andere; über viele Turns ist die Verteilung nicht einseitig.
- Die zwei Think-Stages: Gebote und Entwürfe landen in den `Thought`s, alle Agenten werden gefragt,
  das Kurzzeitgedächtnis aller wird geschrieben.
- `StudyAgent::prompt()` wirft bei einem fremden Provider (Abschnitt 4.6).
- `PipelineSmokeTest` findet die drei Pipelines selbst; seine `fakeAnswers()` brauchen Antworten mit
  den neuen Markern.
- Ein Test, der den Judge-Widerspruch ausschließt: die höchste Bewertung gewinnt, auch wenn der
  Antworttext etwas anderes suggeriert.

## 8. Reihenfolge

1. `TieBreaker` mit Tests — alles andere hängt daran.
2. `Purpose::Judge`, `SelectorAgent`, `JudgeAgent`, `StudyAgent::prompt()`-Riegel, `Memory::sharedFor()`.
3. `MARKER_THOUGHT` zieht von `ThinkAsSpeaker` nach `Thinking`.
4. `ThinkAndPrioritize` plus View, `HighestBidSelector`, `ScorePipeline`, Label — die erste Zelle
   läuft.
5. `OrchestratorSelector` plus View, `OrchestratorPipeline`, Label.
6. `ThinkAndPropose` plus View, `JudgeSelector`, `QualityPipeline`, Label.
7. `CLAUDE.md` und `docs/pipeline.puml` nachziehen.

Nach jedem Schritt ist die Suite grün. Schritte 4 bis 6 sind je eine vollständige, einzeln testbare
Zelle.

## 9. Risiken

| Risiko | Umgang |
|---|---|
| Ein Selector umgeht `ask()` und nimmt das Standardmodell | der Riegel in 4.6, mit Test |
| Gebote und Entwürfe sind von Think-Turns nicht zu trennen | 4.5, plus `selection.selector` im Log |
| Fallbacks verzerren die Auswahl unbemerkt | jeder Fallback steht in `signals`, die Auswertung kann ihn berichten oder den Turn ausschließen |
| Der parallele Pfad wird erstmals mit n > 1 benutzt | die Serialisierungsregel aus dem Migrations-Review gilt: Closures fangen nur Skalare, `static` |
| Quality kostet doppelte Textproduktion | bewusst: die Messgröße muss aus derselben Quelle kommen wie in den anderen Zellen |
| Ein Judge oder Orchestrator mit Tools | nicht vorgesehen; `RecordPromptLog` protokolliert nur den ersten Step einer Invocation |

## 10. Bewusst nicht gebaut

Keine Anweisungen an den Sprecher — der alte Moderator gab `role`, `agendaStep` und
`convergenceIntent` mit, und genau das würde die Wortzahl beeinflussen, also die zweite Messgröße.
Keine Ausgleichsregeln, keine Obergrenze für Wiederholungen. Keine Kandidatenvorauswahl (der alte
`FunnelStrategy`). Keine Persona-/Statusschicht und kein `RunExperiment` — eigene Arbeitspakete, ohne
die diese Arbeit vollständig testbar ist.

## 11. Akzeptanzkriterien

- `PipelineRegistry` findet vier Pipelines, `PipelineSmokeTest` fährt jede zwei Turns.
- Ein Score-Turn schreibt die Gebote aller Agenten nach `job_logs.selection.signals.bids`.
- Ein Orchestrator-Turn schreibt die Begründung des Modells nach `job_logs.selection.reasoning`.
- Ein Quality-Turn schreibt die Punkte aller Agenten und der Gewinner ist der mit den meisten.
- Zwei Läufe mit demselben Seed und derselben Modellantwort wählen bei Gleichstand denselben
  Sprecher.
- Jeder Fallback aus 4.8 ist im Log erkennbar, und kein Fallback beendet den Lauf.
- Ein Selector, der `prompt()` mit einem fremden Provider aufruft, scheitert im Test.
- Die Suite ist grün, `vendor/bin/pint` meldet für die berührten Dateien nichts.
