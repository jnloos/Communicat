# Umbau nach dem Code-Review: Roadmap

Stand: 26. September 2026. Branch: `Studie-JanNiclas-Loosen`.
Vorgänger-Spec: `docs/superpowers/specs/2026-09-24-laravel-ai-sdk-migration-design.md`.

Diese Datei ist kein Design, sondern die Zerlegung: sie hält fest, welche sieben Vorhaben aus dem
Code-Review vom 26. September hervorgehen, in welcher Reihenfolge sie gebaut werden und warum
gerade in dieser. Jedes Stück bekommt seine eigene Spec und seinen eigenen Plan und verweist
zurück auf diese Datei.

## 1. Was das Review ergeben hat

Sieben Punkte, teils unabhängig voneinander:

1. **Adjacency-Paartyp entfällt.** Statt eines typisierten Paares wird nur noch festgehalten, wen
   ein Beitrag anspricht.
2. **Structured Output statt Marker-Parsing.** Die Antworten der Modelle werden über JSON-Schemata
   erzwungen, statt mit Regex aus Freitext geschnitten zu werden.
3. **Debug-Report an den Bug-Button.** Heute hängt er in der Sidebar; er gehört in die
   Steuerleiste des Gesprächs, auf das er sich bezieht.
4. **Statistik-Panel hinter dem Statistik-Button**, mit Flux-Diagrammen.
5. **`Values` konsequent führen.** Das Verzeichnis existiert, aber Datentypen liegen auch
   daneben — etwa `MemoryView`.
6. **Konsolenbefehle** für die Projektfunktionen, damit die Studie automatisiert laufen kann.
7. **Prompts vollständig auf Englisch**, der Sprachmix entfällt.

## 2. Getroffene Entscheidungen

| Thema | Entscheidung |
|---|---|
| Prompt-Sprache | alles auf Englisch: Prompts, Personas in `database/experts.json`, Demo-Themen — und damit auch die Gesprächsbeiträge selbst |
| Umfang Structured Output | alle fünf Purposes (`think`, `speak`, `select`, `judge`, `summarize`) |
| Adjacency-Benennung | `addressee`; Spalte `messages.addressee_expert_id`, Relation `$message->addressee` |
| Statistik-Umfang | nur das Projekt, in dessen Chat der Button sitzt; kein Projektvergleich |
| Statistik-Inhalt | Turn-Anteil und Gesprächsanteil in Wörtern als je ein Pie, je ein Gini-Koeffizient darunter, dazu eine Tabelle mit den Rohzahlen. Keine Entropie, keine Zeichen, kein Verlauf, keine Pipeline-Signale, keine Adressierungsmatrix |
| Debug-Ort | Flyout über dem Gespräch, fest auf dieses Projekt; Sidebar-Eintrag entfällt, Route bleibt als Deep-Link |
| Values-Strenge | streng: alles Unveränderliche ohne Verhalten wandert nach `Values`, einschließlich des `Purpose`-Enums |
| CLI-Umfang | volle Projekt-CLI für ein Projekt; der 24-Zellen-Matrix-Runner bleibt ausdrücklich draußen |

## 3. Reihenfolge und ihre Begründung

| # | Stück | Warum hier |
|---|---|---|
| 1 | Values-Konsolidierung | rein mechanisch; die Stücke 2–4 editieren genau diese Dateien, also zuerst verschieben und dann inhaltlich ändern |
| 2 | Adjacency auf `addressee` | legt fest, welche Felder der Speak-Beitrag überhaupt hat — muss vor dem Schemaentwurf stehen |
| 3 | Structured Output | größtes Stück; löscht rund ein Drittel des Prompt-Textes, nämlich alle Formatvorschriften |
| 4 | Prompts auf Englisch | nach 3, weil sonst Text übersetzt würde, den 3 wieder entfernt |
| 5 | CLI Teil 1 (Projekt, Turns) | erzeugt die echten Läufe, an denen 6 und 7 überhaupt prüfbar sind |
| 6 | Metrik-Kern + CLI-Export | eine Implementierung der Zählregeln für Panel, CLI und späteres Auswertungsskript |
| 7 | Panels in der Steuerleiste | Debug und Statistik zusammen, weil beide dieselbe Blade-Datei und dieselben Sprachschlüssel anfassen |

Stück 3 trägt das einzige echte technische Risiko, siehe Abschnitt 4. Es steht bewusst früh, damit
die Verifikation nicht erst am Ende auffällt.

## 4. Offene Risiken

**Anthropic: `output_config` ist doppelt belegt.**
`vendor/laravel/ai/src/Gateway/Anthropic/Concerns/BuildsTextRequests.php:43-53` legt das
JSON-Schema unter `output_config` ab. Genau diesen Schlüssel benutzt `StudyAgent::providerOptions()`
für den Reasoning-Effort. Zeile 71 führt `array_merge($body, $providerOptions)` aus — unsere
Provider-Option gewinnt und löscht das Schema. Lösungsvorschlag für Stück 3:
`use_native_structured_output => false` in der Anthropic-Provider-Konfiguration, dann verwendet das
SDK ein synthetisches Tool (Zeile 166) und `output_config` bleibt frei. Das muss mit **je einem
echten Call pro Provider** überprüft werden; die Testsuite kann es per Konstruktion nicht, weil
`phpunit.xml` die API-Keys leert. Damit erledigt sich zugleich die bislang offene Frage, ob der
Anthropic-Effort-Schlüssel überhaupt ankommt.

**Formatzwang bei `speak`.**
Alle fünf Purposes bekommen ein Schema, `speak` eingeschlossen. Bei den anderen vier ist das
folgenlos — sie liefern Werte, die der Code ohnehin nur als Feld weiterverarbeitet. Bei `speak`
entsteht dagegen die abhängige Variable der Studie, und es gibt Befunde, dass Formatzwang die
Generierung verändert (Tam et al. 2024, „Let Me Speak Freely?"). Verschiebt der JSON-Wrapper die
Beitragslänge, verschiebt er den Gesprächsanteil.

Das ist **kein Konfund zwischen den Bedingungen**: der Zwang ist in allen 24 Zellen identisch,
also konstant. Er verschiebt das absolute Niveau, nicht die Differenz, an der H1 bis H3 hängen.
Zwei verbindliche Maßnahmen:

1. Das Speak-Schema hat genau zwei Felder, `contribution` und `addressee`. Keine Selbstauskunft
   über Länge, Reaktionstyp oder sonstige Metadaten. Je weniger das Modell nebenher ausfüllt,
   desto weniger Aufmerksamkeit geht vom Beitrag ab.
2. **Pilotvergleich, Pflichtbestandteil von Stück 3.** Er klammert das Stück ein, weil die
   Referenz nur vorher existiert:
   - **Vor der ersten Änderung an Stück 3**: ein Lauf auf dem jetzigen Code mit Markern.
     Festes Thema, festes Modell, Round Robin, festes Turn-Budget, fester Seed. Über die UI
     gefahren — die CLI gibt es dann noch nicht.
   - **Nach Stück 3**: derselbe Lauf mit Schema, gleiche Parameter, gleicher Seed.
   - Verglichen wird die mittlere Wortzahl pro Turn aus `job_logs.words`.

   Danach ist die Vergleichsgrundlage endgültig weg: Stück 4 übersetzt die Prompts, und die
   Marker-Fassung lebt nur noch in der Git-Historie. Das Ergebnis geht in den Methodenteil der
   Arbeit („der Formatzwang verschiebt die mittlere Beitragslänge um x %, in allen Bedingungen
   gleichermaßen").

**Vergleichbarkeit — geklärt, kein Risiko mehr.** Die Stücke 2 bis 4 ändern Datenmodell,
Antwortformat und Promptsprache, alte Läufe werden dadurch unvergleichbar. Der Nutzer hat am
26. September bestätigt, dass nichts davon produktiv läuft und keine Daten erhalten bleiben
müssen. Migrationen dürfen daher an Ort und Stelle geändert werden, statt als Folgemigration
hinterhergeschoben zu werden; die Datenbank wird neu aufgebaut und aus `dev:build-suite` gefüllt.
Das gilt für die gesamte Roadmap, nicht nur für Stück 2.

## 5. Was ausdrücklich nicht dazugehört

- Die Persona- und Status-Ebene (Titel, neutraler Zusatztext gleicher Länge, Rotation über die
  Sitze). Sie bleibt das nächste eigenständige Vorhaben nach dieser Roadmap.
- `RunExperiment`, der Runner über die 24 Zellen der Matrix. Er setzt die Status-Ebene voraus;
  vorher gebaut, müsste er zweimal gebaut werden.
- Das Auswertungsskript der Arbeit. Es liest dieselben Zählregeln wie der Metrik-Kern aus Stück 6,
  entsteht aber erst mit den Daten der vollständigen Matrix.
