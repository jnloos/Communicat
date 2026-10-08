# Globale Tags und Statistik-Seite

Stand: 8. Oktober 2026. Branch: `Studie-JanNiclas-Loosen`.

Personas und Projekte bekommen globale Tags aus festen Kategorien. Eine neue Statistik-Seite
bildet daraus Vergleichsreihen über viele Läufe hinweg, und die Sidebar lässt sich nach
Projekt-Tags filtern. Anlass: Nebenschauplätze der Studie (z. B. Geschlecht, Minderheit/Mehrheit,
bekannte Namen) sollen auswertbar werden, ohne für jede Fragestellung Code zu schreiben, und der
Titelträger soll über eine Markierung statt über „Sitz 1“ auffindbar sein.

## 1. Ziele und Nicht-Ziele

**Ziele**
- Admins pflegen Kategorien mit festen Werten, getrennt für Personas und Projekte.
- Formulare für Personas und Projekte zeigen je Kategorie ein Auswahlfeld (höchstens ein Wert).
- Tags sind für die Modelle unsichtbar: kein Prompt, kein `run_config`.
- Eine globale Statistik-Seite mit gespeicherten Vergleichen; eine Reihe ist ein Projekt-Filter
  plus ein Agenten-Bezug.
- Die Sidebar lässt sich nach Projekt-Tags filtern.
- Die vorhandenen Studienzellen werden einmalig vorbelegt.

**Nicht-Ziele**
- Keine Kopplung an die (noch nicht gebaute) Status-Schicht. Tags sind nachträgliche
  Beschreibungen der Forschenden, keine Bedingung eines Laufs.
- Keine Signifikanztests, keine Mixed Models (gehören ins Auswertungsskript).
- Keine Kosten- oder Mechanik-Kennzahlen, kein Bild-Export (spätere Stufe).
- Keine Tags, die nur innerhalb eines Projekts gelten.

## 2. Datenmodell

```
tag_categories   id · scope (persona | project) · name · position · timestamps
                 UNIQUE (scope, name)
tag_values       id · tag_category_id → cascade · name · position · timestamps
                 UNIQUE (tag_category_id, name)
taggables        id · tag_value_id → cascade · tag_category_id → cascade
                 · taggable_type · taggable_id · timestamps
                 UNIQUE (taggable_type, taggable_id, tag_category_id)
comparisons      id · user_id → cascade · name · series (json) · view (json) · timestamps
```

- `scope` ist ein PHP-Enum `TagScope` (`Persona`, `Project`) mit der Zuordnung zur Model-Klasse
  (`Expert`, `Project`). Der Morph-Typ wird über die Morph-Map aufgelöst, nicht über
  Klassennamen-Strings im Code.
- Die Kategorie steht in `taggables` mit, damit die Datenbank „höchstens ein Wert je Kategorie“
  erzwingt. Beim Zuordnen wird die Zeile der Kategorie ersetzt (upsert), nicht ergänzt.
- Ein Trait `HasTags` auf `Expert` und `Project`: `tagValues()` (morphToMany über `taggables`),
  `setTag(TagCategory, ?TagValue)`, `tagFor(TagCategory): ?TagValue`, Scope
  `whereTagged(array $valueIds)` (UND über Kategorien).
- Ein Wert muss zur Kategorie und die Kategorie zum Scope des Modells passen; `setTag` prüft das
  und wirft sonst.
- **Löschen ist erlaubt, aber nur nach Warnfenster.** Das Fenster nennt, was mitfällt („Wert
  *weiblich* ist 7 Personas zugeordnet; diese Zuordnungen werden gelöscht“, bei einer Kategorie
  zusätzlich alle Werte). Zuordnungen fallen per Cascade weg. Umbenennen ist jederzeit erlaubt;
  Zuordnungen und gespeicherte Vergleiche hängen an IDs.
- Wird eine Persona oder ein Projekt gelöscht, verschwinden ihre `taggables`-Zeilen mit (im
  `deleting`-Hook bzw. über den Trait).

## 3. Für die Modelle unsichtbar

- Prompt-Vorlagen lesen weiterhin nur Name, Rolle, Beschreibung und Projektbeschreibung.
- `run_config` nimmt keine Tags auf.
- Ein Test rendert alle Prompt-Vorlagen (über `PromptRenderer`) für Personas und ein Projekt mit
  gesetzten Tags, deren Kategorie- und Wertnamen eindeutige Marker sind, und prüft, dass keiner
  davon im Ergebnis vorkommt.

## 4. Verwaltung: Einstellungen → Tags

- Neue Livewire-Seite `Settings\Tags` unter `settings/tags`, Middleware `admin`, Reiter neben
  „Users“ in `components/settings/layout.blade.php` (nur für Admins sichtbar).
- Zwei Bereiche (Reiter): **Personas** und **Projekte**. Je Bereich die Kategorien als Liste,
  jede mit ihren Werten.
- Anlegen, Umbenennen (inline), Löschen (mit Warnfenster über `NeedsConfirmation`, Text mit der
  Zahl der betroffenen Zuordnungen) für Kategorien und Werte.
- Reihenfolge per Ziehen (`wire:sort`), gespeichert in `position`; gilt auch in Formularen.
- Neben jedem Wert die Zahl seiner Zuordnungen.
- Validierung: Name Pflicht, max. 50 Zeichen, eindeutig im Scope bzw. in der Kategorie.

## 5. Zuordnung in Formularen

- Wiederverwendbare Livewire-Komponente `Tags\TagFields` (Props: Scope, Modell bzw. leer beim
  Anlegen). Sie zeigt je Kategorie des Scopes ein `flux:select` mit Option „—“ (nicht gesetzt).
  Gibt es keine Kategorie, rendert sie nichts.
- Beim Anlegen hält sie die Auswahl, bis das Elternformular gespeichert hat; das Elternformular
  übergibt die Auswahl beim Speichern (Event oder Modelable-Property, je nachdem, was zum
  bestehenden Formularfluss passt — im Plan entschieden).
- Eingebaut in `Experts\ExpertEditor`, `Projects\CreateProject`, `Projects\EditProject`.
- Rechte: Zuordnen darf, wer das Objekt bearbeiten darf (Projekte: Gate `manage-project`;
  Personas: wie heute der Experten-Editor).
- Anzeige: Werte als kleine Badges in der Persona-Liste und in den Projekteinstellungen.

## 6. Statistik-Seite

- Sidebar-Eintrag **Statistics** im unteren Navigationsblock (bei Einstellungen), Route
  `/statistics`, Livewire-Seite `Statistics\StatisticsPage`. Für alle angemeldeten Nutzer;
  ausgewertet werden nur Projekte, auf die der Nutzer Zugriff hat (Gate `access-project`).
- Links die eigenen Vergleiche (anlegen, umbenennen, duplizieren, löschen mit Warnfenster),
  rechts der geöffnete Vergleich.

**Reihe** (`series[]` im JSON): `label`, `projects` (Filter), `reference` (Bezug). Farben werden
der Reihe nach aus einer festen Palette vergeben, unabhängig von Sitzfarben.

- **Projekt-Filter:** Projekt-Tag-Werte (UND über Kategorien, ODER innerhalb einer Kategorie),
  optional Pipeline und Modell (bestehende Felder). Unter dem Filter live: Anzahl und Titel der
  passenden Projekte.
- **Bezug** (welche Agenten eines Projekts):
  - `persona_tags` — Persona-Tag-Werte (UND über Kategorien, ODER innerhalb)
  - `seat` — ein Sitz
  - `expert_name` — ein Experte nach Name (über alle Datensätze gleichen Namens)
  - `top` — der Agent mit dem höchsten Wert der gewählten Kennzahl im Lauf
  - `each` — alle Agenten einzeln (eine Teilreihe je Sitz)
- Trifft der Bezug mehrere Agenten eines Projekts, zählt ihr **gemeinsamer** Anteil (Summe).
  Trifft er keinen, fällt das Projekt für die Reihe heraus; die Seite nennt die Zahl.

**Ansicht** (`view` im JSON, gilt für alle Reihen):
- Kennzahl: Turn-Anteil, Wortanteil, Wörter pro Turn, Gini (Turns oder Wörter; Lauf-Kennzahl,
  ignoriert den Bezug).
- Darstellung: Tabelle (Wert je Projekt; Mittelwert, Min, Max, n je Reihe), Balken je Reihe mit
  Spannweite, Verlauf über die Turns (kumulativ/gleitend; Turns/Wörter; eine gemittelte Linie je
  Reihe, gemittelt über die Projekte, die den Turn erreicht haben, n im Tooltip). Der Verlauf
  steht nur für Turn- und Wortanteil zur Verfügung.
- Turn-Grenze: optional nur die ersten N gesprochenen Turns.

**Rechnen:**
- `App\Discussion\Metrics\SeriesStatistics` nimmt eine Reihe und eine Ansicht und liefert
  Werte-Objekte (neu unter `App\Discussion\Values`). Sie nutzt je Projekt `SpokenTurns`,
  `SpeakingShares`/`ShareTrajectory`; Zählregeln werden nicht neu geschrieben.
- `SpeakingShares` und `ShareTrajectory` bekommen dafür eine optionale Turn-Grenze, ohne ihr
  Standardverhalten zu ändern.
- Bezüge: Schnittstelle `AgentReference` („welche Experten dieses Projekts“) mit je einer
  Klasse pro Bezug; neue Bezüge sind neue Klassen.
- Gelöschte Tag-Werte in gespeicherten Filtern fallen beim Laden heraus, mit Hinweis.

## 7. Sidebar-Filter

- In `Projects\ProjectNavigation` oberhalb der Liste ein Filter-Knopf, der die Projekt-Kategorien
  mit ihren Werten zeigt (Mehrfachauswahl; UND über Kategorien, ODER innerhalb).
- Nicht passende Projekte werden ausgeblendet; leere Gruppen ebenfalls. Ein aktiver Filter ist
  am Knopf sichtbar und mit einem Klick zurücksetzbar.
- Der Filter ist persönlich und bleibt über Seitenwechsel erhalten (Livewire `#[Session]`).

## 8. Vorbelegung der vorhandenen Zellen

Artisan-Befehl `tags:seed-study`, standardmäßig Trockenlauf (zeigt die geplante Zuordnung als
Tabelle), mit `--apply` schreibend; idempotent.

- Projekt-Kategorie `bedingung` mit Werten `status`, `kontrolle`: aus dem Titel
  (`with status` / `without status`), nur für Projekte, deren Titel das Schema trägt.
- Persona-Kategorie `titeltraeger` mit Wert `ja`: die Persona auf Sitz 1 jedes Projekts mit
  `bedingung: status`. Taucht dieselbe Persona auch auf einem Projekt mit `bedingung: kontrolle`
  auf, wird sie nicht markiert, sondern als Konflikt gemeldet.
- Fehlende Kategorien und Werte legt der Befehl an.
- Der Trockenlauf wird dem Nutzer vor `--apply` gezeigt.

## 9. Export/Import

- `ProjectExport` nimmt die Projekt-Tags als `{kategorie: wert}` nach Namen mit.
- `ProjectImporter` ordnet sie nach Namen zu; unbekannte Kategorien oder Werte werden übersprungen
  und gemeldet, nicht angelegt.

## 10. Tests

- Unit: `HasTags` (ein Wert je Kategorie, Scope-Prüfung, `whereTagged`), Cascade beim Löschen,
  `SeriesStatistics` an synthetischen Extremfällen (gemeinsamer Anteil mehrerer Agenten, Bezug
  ohne Treffer, Turn-Grenze, Mittelung über ungleich lange Läufe), jede `AgentReference`.
- Feature: Tag-Verwaltung (nur Admin; Anlegen, Umbenennen, Löschen nach Bestätigung, Sortieren),
  `TagFields` in den drei Formularen, Statistik-Seite (Zugriff nur auf eigene/zugängliche
  Projekte, Vergleich speichern und öffnen), Sidebar-Filter, `tags:seed-study` (Trockenlauf
  schreibt nichts, `--apply` idempotent, Konfliktmeldung), Export/Import.
- Unsichtbarkeit für die Modelle (Abschnitt 3).

## 11. Offene Punkte für den Plan

- Wie `TagFields` beim Anlegen die Auswahl an das Elternformular übergibt (abhängig vom
  bestehenden Formularfluss in `CreateProject`/`ExpertEditor`).
- Ob `flux:chart` Spannweiten im Balkendiagramm direkt kann oder ob die Spannweite als zweite
  Serie bzw. Fehlerbalken gezeichnet wird.
