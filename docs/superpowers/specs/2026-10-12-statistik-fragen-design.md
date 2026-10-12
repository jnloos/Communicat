# Statistik-Seite als Frage: Design

Stand 12.10.2026, Branch `tags-und-statistik`. Ersetzt den Reihen-Editor der Statistik-Seite (Spec
`2026-10-08-…`), lässt die Rechenschicht (`SpokenTurns`, `SpeakingShares`, `ShareTrajectory`, `Gini`)
unverändert.

## Problem

Die Seite war ein Baukasten für Reihen: jede Reihe eine Karte (Projektfilter + Agentenbezug), zehn fast
gleiche Karten für eine Matrix, das Ergebnis ganz unten, die Darstellung frei wählbar (Tabelle, Balken,
Verlauf, Kreis). Folgen, im Browser nachgestellt: summierte Gruppen gegen eine Gleichverteilungslinie für
Einzelagenten (Round Robin 25 % gegen 75 % sah nach Effekt aus und war keiner), eine Reihe ohne Filter
weitete sich still auf alle Projekte aus, Name und Inhalt eines Vergleichs liefen auseinander.

## Leitidee

**Eine Frage ist ein Satz. Die Antwort hat immer dieselbe Form: faire Erwartung → jeder Lauf als Punkt →
Abstand zur Erwartung oder zur Vergleichsgruppe. Darunter die Läufe als Beleg.**

Frageform aus Produkt-Analytics (Amplitude, Mixpanel, Metabase: Metrik × Population × Breakdown, als Satz),
Antwortform aus Experiment-Plattformen und Estimation Statistics (Rohpunkte, Delta; Weissgerber 2015,
DABEST), Referenz aus Redezeit-Fairness-Studien (Anteil gegen Präsenz; Hinsley 2017), Beleg aus
Experiment-Tracking (W&B: Lauftabelle unter jeder Zahl). Recherche vom 12.10.2026 im Gesprächsverlauf.

## Die Frage

```
[Messgröße] von [Personen] ([zusammen | pro Kopf]) in [Läufen …]
verglichen nach [Dimension] aufgeteilt nach [Dimension]
```

| Satzteil | Werte | Regeln |
|---|---|---|
| Messgröße | Turn-Anteil, Wortanteil, Wörter pro Turn, Gini (Turns), Gini (Wörter) | `Metric`, unverändert |
| Personen | alle; Persona-Tags (UND über Kategorien, ODER innerhalb); ein Name | Gini ignoriert Personen |
| zusammen / pro Kopf | nur bei Anteilen | pro Kopf = Gruppenanteil ÷ Gruppengröße |
| Läufe | Projekt-Tags, Pipelines (ODER), Modelle (ODER), Turn-Budget | kein Filter = ausdrücklich „alle Läufe“ |
| verglichen nach | Persona-Tag-Kategorie, Projekt-Tag-Kategorie, Pipeline, Modell, nichts | Persona-Ebene: Gruppen innerhalb derselben Läufe; Projekt-Ebene: Gruppen zwischen Läufen; bei Gini nur Projekt-Ebene |
| aufgeteilt nach | Projekt-Tag-Kategorie, Pipeline, Modell, nichts | nie dieselbe Dimension wie „verglichen nach“; ergibt die Panels |

Es gibt kein Turn-Limit mehr; Läufe unterschiedlicher Länge trennt der Filter Turn-Budget.

## Die Antwort

Drei Formen, abgeleitet aus der Frage, nicht frei kombinierbar:

- **Punkte** (immer): je Panel eine Zeile je Gruppe, jeder Lauf ein Punkt, Mittelwert als Strich, faire
  Erwartung gestrichelt, n. Bei genau zwei Gruppen das Delta (zweite − erste, in Prozentpunkten bei Anteilen).
- **Anteile** (nur wenn die Gruppen zusammen 100 % ergeben: Anteil, Vergleich auf Persona-Ebene, alle
  Personen, zusammen): je Panel ein 100-%-Stapelbalken. Keine Kreise (Cleveland & McGill 1984, Datawrapper).
- **Verlauf** (nur Anteile): je Panel eine Kurve je Gruppe, kumuliert oder gleitend.

**Faire Erwartung** einer Gruppe in einem Lauf: zusammen = Gruppengröße ÷ Agenten im Lauf, pro Kopf =
1 ÷ Agenten im Lauf; für Nicht-Anteile keine. Gezeigt wird der Mittelwert über die Läufe.

Agenten ohne Wert in der „verglichen nach“-Kategorie bilden die Gruppe „ohne Angabe“, damit Anteile 100 %
ergeben. Ein Lauf ohne gesprochenen Turn oder ohne passende Person fällt heraus und wird gezählt.

Unter dem Ergebnis aufklappbar: die Läufe (Titel mit Link zum Chat, Panel, Gruppe, Wert).

## Speichern und Vorlagen

- Eine Frage speichert sich bei jeder Änderung. Ohne eigenen Namen heißt sie wie ihr Satz.
- **Vorlagen** liegen in derselben Tabelle (`comparisons.is_template`). „Als Vorlage speichern“ legt eine
  Kopie als Vorlage an. Vorlagen sehen alle angemeldeten Nutzer; ein Klick legt eine eigene Kopie an und
  öffnet sie. Umbenennen und Löschen darf nur der Besitzer. Eine Vorlage selbst wird nicht bearbeitet.
- Die Thesis-Vorlagen spielt einmalig `ThesisTemplatesSeeder` ein (Besitzer: erster Admin); fehlt eine
  Tag-Kategorie oder ein Wert, überspringt er die Vorlage und sagt es.
- Bestehende Vergleiche werden bei der Migration verworfen (Spalten `series`/`view` entfallen).

## Daten

- Neue Persona-Tag-Kategorie **`focus persona`** (Wert `true`) auf **beiden** Experten-Datensätzen jeder
  Persona, die in der Status-Bedingung den Titel trägt (Alex Brandt, Sam Delius, Mika Sommer, Nikita Falk).
  Grund: in no-status-Läufen ist dieselbe Persona ein eigener Datensatz mit `title-holder: false`.
  `title-holder` behält die Bedeutung „trägt in diesem Lauf den Titel“.
- Einmaliges Setzen per Tinker nach DB-Backup, kein Kommando im Code.

## Bewusst nicht enthalten

- Gepaarte Darstellung (Linie je Thema mit/ohne Titel) und ein Projekt-Tag `topic`: später, wenn eine
  Analyse sie braucht.
- „Jeder Agent einzeln“ über Läufe hinweg (Namen unterscheiden sich je Thema; Einzellauf zeigt der Flyout).
- Konfidenzintervalle (bei n = 4 instabil); Spanne und n stehen dabei.
- Farbwahl pro Reihe: Farben folgen der Gruppe (erste, zweite, … der Kategorie).

## Unverändert

Rechenschicht, Statistik-Flyout im Chat (behält seine Kreise), `project:metrics`, `project:cost`,
Tag-System, Sidebar-Filter, Diskussions-Pipeline.
