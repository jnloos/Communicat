# Statuseffekt in LLM-Gruppendiskussionen: Zwischenbericht, Stand 18.10.2026

**Stand:** 18.10.2026 · **Zweck:** Zwischenbericht für die Besprechung zur Masterarbeit, Fortschreibung des
Berichts vom 01.10.2026 · **Datenbasis:** alle 126 Läufe in der Datenbank (ohne den Testlauf 24), direkt
nachgerechnet aus `job_logs`/`messages`, Zählregel wie in `SpokenTurns` (gesprochen = `words IS NOT NULL`,
Wörter statt Tokens).

**Kurzfassung:** Seit dem 01.10. kamen vier Dinge dazu: ein Judge ohne Rollenansicht (Blind-Quality), ein
Sitztest (Titel auf Sitz 3 sowie eine Wiederholung auf Sitz 1), eine Begründung der Chatlänge (Empfehlung 100
Turns) und ein Gender-Test mit Dreiergruppen. Die Richtung bleibt: In 29 von 32 Paarvergleichen mit freier
Sprecherwahl spricht die Persona mit Titel häufiger als dieselbe Persona ohne Titel. Drei Befunde sind neu.
**Erstens** wirkt der Titel auch dann, wenn der Judge ihn nicht sieht (66 % gegen 23 %, Sichtbarkeit erklärt
nur etwa 5 Prozentpunkte des Quality-Effekts). **Zweitens** hängt der Effekt von der Sitzposition ab, beim
Orchestrator deutlich (Sitz 3: +16 statt +30 bis +35 Prozentpunkte, nur 2 von 4 Paaren positiv). **Drittens**
bekommt der einzige Mann in einer Frauengruppe mehr Redeanteil als die einzige Frau, aber der Befund liegt
bei p ≈ 0,19 und ist ein Hinweis, kein Beleg. Jede Zelle ist weiterhin ein einzelner Lauf (n = 1, Seed 42,
ein Modell), die Streuung zwischen Themen liegt bei bis zu 45 Prozentpunkten. Vor der vollen Matrix fehlen
weiterhin der Persona-/Status-Layer mit Rotation über alle Sitze, das Auswertungsskript und der Matrix-Runner.

> Alle Zahlen sind deskriptiv. Pro Zelle liegt ein Lauf pro Thema vor (vier Themen), alle Läufe auf
> `openai-gpt-5-mini`. Die 32 Paarvergleiche sind nicht unabhängig: Themen und Seed wiederholen sich, und
> die Wiederholungsläufe stehen neben ihren Originalen.

---

## 1. Was seit dem 01.10. gelaufen ist

| Reihe | Projekte | Pipelines | Läufe | Was verändert wurde |
|---|---|---|---|---|
| Status (Ausgangsreihe) | 16–23, 25–32, 41–56 | Score, Orchestrator, Quality, Round Robin | 32 | Stand 01.10., siehe Bericht vom 01.10. |
| Blind-Quality | 63–70 | Quality mit blindem Judge | 8 | Judge sieht weder Namen noch Rollen noch Adressierung |
| Chatlänge | 57–62 | Score, Orchestrator, Quality | 6 | 100 statt 60 Turns, nur mit Status |
| Sitztest | 71–102 | Quality, Orchestrator | 32 | Titel auf Sitz 3 (16 Läufe) und unveränderte Wiederholung auf Sitz 1 (16 Läufe) |
| Gender | 127–174 | Score, Orchestrator, Quality | 48 | Dreiergruppen, nur der Vorname der Fokuspersona wechselt |

Insgesamt 126 Läufe mit 7.800 gesprochenen Beiträgen. Alle Läufe haben ihr Turn-Budget erreicht.

## 2. Statuseffekt: Gesamtbild

Turn-Anteil der Persona mit Titel gegen dieselbe Persona ohne Titel (Mittel über die vier Themen School, Office,
Family, Club; Differenz in Prozentpunkten; Paare mit positivem Vorzeichen):

| Mechanismus | Sitz des Titels | mit Titel | ohne Titel | Differenz | positive Paare |
|---|---|---|---|---|---|
| Score | 1 | 56,7 % | 25,0 % | +31,7 | 4 von 4 |
| Orchestrator | 1 (Ausgangsreihe) | 42,5 % | 7,9 % | +34,6 | 4 von 4 |
| Orchestrator | 1 (Wiederholung) | 47,9 % | 18,3 % | +29,6 | 4 von 4 |
| Orchestrator | **3** | 47,5 % | 31,2 % | **+16,2** | **2 von 4** |
| Quality (Judge sieht Rollen) | 1 (Ausgangsreihe) | 85,0 % | 36,2 % | +48,8 | 4 von 4 |
| Quality (Judge sieht Rollen) | 1 (Wiederholung) | 67,5 % | 17,5 % | +50,0 | 4 von 4 |
| Quality (Judge sieht Rollen) | **3** | 85,0 % | 40,0 % | +45,0 | 3 von 4 |
| Blind-Quality | 1 | 66,2 % | 22,5 % | +43,8 | 4 von 4 |
| Round Robin | 1 | 25,0 % | 25,0 % | 0 (Konstruktion) | – |

- **29 von 32** Paaren mit freier Sprecherwahl sind positiv (Score 4/4, Orchestrator 10/12, Quality 11/12,
  Blind-Quality 4/4). Ein Vorzeichentest läge bei p ≈ 1,3 · 10⁻⁶, ist hier aber wegen der Abhängigkeiten
  nicht belastbar. Die Aussage trägt nur als Richtung.
- **Wortanteil:** Der Effekt ist beim Wortanteil meist größer als beim Turn-Anteil, weil der Titelträger pro
  Beitrag mehr schreibt (Score 62,4 % gegen 23,9 %, Orchestrator 45,9 % gegen 10,1 %, Quality 85,6 % gegen
  36,5 %, Blind-Quality 66,3 % gegen 21,1 %).
- **Round Robin** (Turn-Anteil per Konstruktion 25 %): Wortanteil des Titelträgers 31,4 % gegen 26,1 %,
  +5,2 Prozentpunkte, in 4 von 4 Paaren positiv.
- **Ein Agent dominiert auch ohne Titel** (häufigster Sprecher, mit / ohne Titel: Score 56,7 % / 40,4 %,
  Orchestrator 69,2 % / 77,5 %, Quality 85,0 % / 63,3 %, Blind-Quality 67,9 % / 45,8 %). Lesart wie bisher:
  Der Titel erzeugt die Ungleichheit nicht, er bestimmt, auf wen sie fällt.

## 3. Neue Befunde

### 3.1 Der blinde Judge: Der Titel wirkt auch ohne Sichtbarkeit

Am 01.10. war offen, ob der starke Quality-Effekt an der Sichtbarkeit des Titels im Judge-Prompt liegt oder
am Inhalt der Entwürfe. Dafür gibt es seit der Reihe 63–70 die Variante *Blind-Quality*: Der Judge bekommt keine
Teilnehmerliste, Verlauf und Zusammenfassung ohne Namen und Adressierungspfeile, die Entwürfe als A bis D mit
pro Turn gemischter Reihenfolge. Sonst ist alles gleich (gleicher Entscheidungssatz, gleiche Entwürfe).

| Judge | mit Titel | ohne Titel | Differenz | positive Paare |
|---|---|---|---|---|
| sieht die Rollen (Quality, Ausgangsreihe) | 85,0 % | 36,2 % | +48,8 | 4 von 4 |
| blind (Blind-Quality) | 66,2 % | 22,5 % | +43,8 | 4 von 4 |

- **Etwa 5 der knapp 49 Prozentpunkte** hängen an der Sichtbarkeit. Der größere Teil läuft über den Inhalt:
  Die Entwürfe der Persona mit Titel sind offenbar auch ohne Etikett die, die der Judge wählt.
- **Der Weg über den Inhalt ist offen gelassen:** Selbstbezüge („As CEO …“) und Namensadressen in den
  Entwürfen sind nicht entfernt, und die Zusammenfassung kann Namen enthalten. Die Variante misst also,
  ob der Judge den Titel *sieht*, nicht ob Status überhaupt im Text steckt.
- **Adressierung bleibt hoch:** Auch beim blinden Judge wird die Titelpersona in 57,4 % der adressierten
  Beiträge angesprochen, gegen 10,2 % ohne Titel. Die Agenten tragen den Status selbst in die Entwürfe.
- **Einschränkung:** vier Paare je Variante, keine Wiederholung. Der Unterschied von 5 Prozentpunkten liegt
  weit innerhalb der Streuung zwischen Themen.

### 3.2 Sitztest: Die Position ändert den Effekt, besonders beim Orchestrator

Bisher saß der Titel immer auf Sitz 1. Der Sitztest hat ihn für Quality und Orchestrator auf Sitz 3 gesetzt und
zugleich Sitz 1 unverändert wiederholt (Tabelle in Abschnitt 2).

- **Wiederholung Sitz 1:** Quality +50,0, Orchestrator +29,6, beide 4 von 4. Die Ausgangsreihe wird
  reproduziert (Quality +48,8, Orchestrator +34,6). Ein Aufblähen durch Sitz 1 gibt es nicht.
- **Quality ist weitgehend sitzunabhängig:** Sitz 3 +45,0 (3 von 4). Office ist dort negativ (−13).
- **Orchestrator ist es nicht:** Sitz 3 nur +16,2, 2 von 4 Paaren positiv (School −32, Office −10, Family
  +37, Club +70). Gleichrangige Zellen zeigen eine Grundrate, die an der Position hängt: Sitz 1 nur 12 %,
  Sitz 2 und 3 zwischen 33 und 40 %, Sitz 4 15 % (zwölf Läufe, Standardabweichung etwa 35 Prozentpunkte,
  Persona und Sitz noch vermischt). Der Orchestrator bevorzugt offenbar mittlere Sitze, das drückt den
  Effekt auf Sitz 3.
- **Adressierung des Titelträgers** (mit / ohne Titel, Sitz 1 / Sitz 3): Orchestrator 57,0 / 33,2 % und
  35,8 / 17,8 %, Quality 35,2 / 25,9 % und 25,3 / 21,2 %. Auf Sitz 3 wird der Titelträger weniger angesprochen
  als auf Sitz 1.
- **Wiederholungen derselben Zelle schwanken je Thema um bis zu 45 Prozentpunkte** (Quality Family: +57 in
  der Ausgangsreihe, +10 in der Wiederholung). Vier Themen trennen das nicht sauber.
- **Folgerung:** Rotation über alle vier Sitze ist nötig, besonders für den Orchestrator, und der Sitz gehört
  als Kovariate ins Modell. Score und Round Robin sind im Sitztest nicht gelaufen.

### 3.3 Chatlänge: 60 Turns sind zu kurz, Empfehlung 100

Bericht `docs/studie/2026-10-08-chatlaenge.md`, Kriterium vorab festgelegt (kumulativer Anteil ändert sich über
die folgenden 10 Turns um höchstens 2 Prozentpunkte). Kurz:

- Nach 60 Turns erfüllen nur 4 von 24 Läufen das Kriterium, und zwar Extremfälle.
- Der Anteil des Titelträgers bewegt sich zwischen Turn 60 und 100 noch um 3 bis 7 Prozentpunkte, unter
  Quality systematisch nach oben (School 75 → 82 %, Office 82 → 89 %). Ein 60-Turn-Lauf unterschätzt dort den
  Effekt, je nach Mechanismus unterschiedlich stark.
- Mit 100 Turns ist der Wortanteil in allen sechs Läufen stabil, der Turn-Anteil in vier.
- Länger als 100 lohnt nicht: Ein zusätzlicher Lauf bringt mehr als 60 zusätzliche Turns.
- **Widerspruch im aktuellen Stand:** Sitztest und Gender-Test (80 Läufe) laufen trotzdem mit 60 Turns. Ihre
  Effekte sind damit unter Quality vermutlich unterschätzt. Die Entscheidung ist offen (Abschnitt 8).

### 3.4 Gender-Test: Der einzige Mann gewinnt mehr als die einzige Frau

Dreiergruppen (nicht vier). Die Fokuspersona wird nur über den Vornamen manipuliert (Anna → Mark), die beiden
anderen heißen unverändert Sarah und Laura (bzw. in der Gegenprobe David und James). Turn-Anteil der
Fokuspersona:

| Mechanismus | alle Frauen (Anna) | einziger Mann (Mark) | alle Männer (Mark) | einzige Frau (Anna) |
|---|---|---|---|---|
| Score | 36,2 % | 47,9 % | 32,5 % | 36,7 % |
| Orchestrator | 12,5 % | 21,7 % | 28,3 % | 24,6 % |
| Quality | 32,9 % | 65,4 % | 27,1 % | 40,0 % |

| Vergleich (Differenz in Prozentpunkten) | Score | Orchestrator | Quality | positive Paare (von 12) |
|---|---|---|---|---|
| einziger Mann − alle Frauen | +11,7 | +9,2 | +32,5 | 8 |
| einzige Frau − alle Männer | +4,2 | −3,7 | +12,9 | 6 |
| einziger Mann − einzige Frau | +11,3 | −2,9 | +25,4 | 8 |

- **Der Manipulationscheck trägt:** In 720 Beiträgen mit Mark kommt „he/him/his“ 106-mal vor, in 720 Beiträgen
  ohne Mann 23-mal (dann nur über andere Personen). Nach „Anna“ stehen 20 „she/her“ und kein „he“.
- **Einziger Mann gegen einzige Frau:** acht von zwölf Paaren positiv, Mittel +11,2 Prozentpunkte,
  Vorzeichentest einseitig p ≈ 0,19. Das ist ein Hinweis, kein Befund. Einzelthemen schwanken um ±50
  Prozentpunkte.
- **Mechanismen:** Am stärksten bei Quality (+25,4), bei Score +11,3, beim Orchestrator kein Effekt (−2,9).
- **„Einziger zu sein“** erklärt höchstens einen Teil: Die einzige Frau gewinnt gegenüber der reinen
  Männergruppe nur mit +4,2 (Score) bzw. +12,9 (Quality) und verliert beim Orchestrator.
- Der Test ist ein Nebenzweig der Studie. Eine Entscheidung, ob er in die Arbeit gehört, steht aus.

## 4. Was aus dem Bericht vom 01.10. weiter gilt

- **Selbstbehauptung bei Score:** Mit Titel bietet Sitz 1 höher (mittlere Gebote 4,1 bis 4,8 gegen 3,3 bis 4,3
  derselben Persona ohne Titel). Der Effekt entsteht in der Selbsteinschätzung, nicht erst beim Auswählen.
- **Ehrerbietung:** Der Titelträger wird in allen Mechanismen häufiger angesprochen. Anteil der adressierten
  Beiträge an den Titelträger, mit / ohne Titel: Score 52,4 / 32,4 %, Orchestrator 61,4 / 19,5 %, Quality
  31,4 / 26,6 %, Blind-Quality 57,4 / 10,2 %, Round Robin 71,8 / 18,8 %.
- **Längere Beiträge:** Der Titelträger schreibt pro Beitrag mehr (Wort- über Turn-Anteil, siehe Abschnitt 2).

## 5. Technik und Kosten

| | Läufe | gesprochene Beiträge | Turns mit Status *failed* | LLM-Aufrufe |
|---|---|---|---|---|
| Status (alle vier Pipelines) | 32 | 1.920 | 2 | 7.748 |
| Blind-Quality | 8 | 480 | 4 | 2.913 |
| Chatlänge | 6 | 600 | 2 | 2.832 |
| Sitztest | 32 | 1.920 | 3 | 8.717 |
| Gender | 48 | 2.880 | 8 | 11.638 |
| **Summe** | **126** | **7.800** | **19** | **33.848** |

- **Fehlerrate gering:** 19 von etwa 7.800 Turns haben den Status *failed* (0,24 %), 16 Aufrufe sind
  fehlgeschlagen. Alle Läufe haben das Budget trotzdem gefüllt.
- **Keine unlesbaren Selektor-Antworten** in den Quality-, Orchestrator- und Blind-Läufen.
- **Gleichstände bei Score:** 918 von 1.640 Turns (56 %) hat der Zufall entschieden, unverändert wie am 01.10.
  Ein Effekt wird dadurch eher unter- als überschätzt.
- **Kosten:** Aus den Token-Zählern der `prompt_logs` ergeben sich bei den gpt-5-mini-Preisen (0,25 $ pro
  Million Eingabe-, 2,00 $ pro Million Ausgabe-Token) rund **111 $** für alle 126 Läufe. Dieselbe Rechnung
  trifft die Kosten der Ausgangsreihe Score auf den Cent (7,93 $). Gelöschte Läufe sind nicht eingerechnet.
- **Kosten pro Lauf bei 60 Turns und vier Agenten:** Round Robin ≈ 0,40 $, Orchestrator ≈ 0,50–0,54 $, Score
  ≈ 1,00 $, Quality und Blind-Quality ≈ 1,25 $. Mit Dreiergruppen etwas weniger (Score 0,77 $, Quality
  1,01 $). 100 Turns kosten etwa das 1,7-fache.
- **Für die volle Matrix** bleiben die Kosten bei gpt-5-mini niedrig: ein Durchgang der vier Pipelines über
  vier Themen und zwei Statusbedingungen kostet etwa 25 $. Die Rotation über vier Sitze vervielfacht nur die
  Zellen mit Titel. Preise der anderen beiden Modellfamilien sind nicht eingerechnet.

## 6. Offene Punkte im Versuchsaufbau, Stand der Dinge

1. **Kontrolle durch Tausch des Rollenetiketts** (statt neutralem Zusatztext nach Qu2026): unverändert, ob der
   Betreuer das so akzeptiert, ist unbeantwortet.
2. **Der Titel saß auf Sitz 1 und, im Sitztest, auf Sitz 3.** Teilweise gelöst: Sitz 3 ist für Quality und
   Orchestrator gelaufen, Sitz 2 und 4 fehlen, ebenso Score und Round Robin. Die eigentliche Rotation
   (Persona-/Status-Layer) ist nicht gebaut.
3. **Abdrift in Umsetzungsplanung:** Das Längenlimit im Speak-Prompt, die Regel gegen Wiederholungen und die
   Kriterien im Judge-Prompt sind entfernt. Ob die Abdrift dadurch tatsächlich nachgelassen hat, ist nicht
   gemessen.
4. **Code-Stände sind nicht identisch:** Die Orchestrator-Ausgangsreihe läuft mit den Prompts vom 29.09., die
   Wiederholung im Sitztest mit den aktuellen. Chatlänge-Kopien und ihre Originale sind keine Replikate.
   Für die Hauptstudie muss der Code für alle Zellen eingefroren sein. Die Refaktorierung vom 15.10. (14
   Commits) ist durch einen Golden-Master-Vergleich als verhaltensgleich geprüft, aber nicht mit neuen
   Läufen.
5. **Lange Beiträge** (200–400 Wörter mit Aufzählungen): bewusst nicht gedeckelt, gehört in die Limitationen.
6. **Eine Beobachtung pro Zelle:** Alle Läufe haben Seed 42. Wiederholungen mit anderen Seeds gibt es nur für
   Sitz 1 (Quality, Orchestrator) und zeigen Streuungen bis 45 Prozentpunkte.
7. **Nur ein Modell.** Der Betreuer möchte das Modell festsetzen (Angabe vom 18.10.2026). Welches Modell das ist
   und ob die Matrix damit auf eine Modellfamilie schrumpft, ist offen und ändert H3 (Modell moderiert) und die
   Kosten. Bis zur Entscheidung läuft alles auf gpt-5-mini. Die Modellfamilie Anthropic hat bisher nur einen echten
   Testaufruf bestanden (native strukturierte Ausgabe mit adaptivem Thinking, 15.10.), Gemini ist nicht gelaufen.

## 7. Nächste Schritte (Vorschlag)

1. **Persona-/Status-Layer:** Titel, neutraler Zusatztext, Rotation über alle Sitze, ohne den restlichen Prompt
   anzufassen.
2. **Auswertungsskript** direkt aus den Logs (gemischte Modelle mit Status × Mechanismus und Status × Modell,
   Thema und Lauf als zufällige Effekte, Varianzzerlegung, Entropie, Übernahme der Position des
   Titelträgers), an synthetischen Extremfällen validiert.
3. **Pilot zur Teststärke:** Wiederholungen pro Zelle (neue Seeds) bei eingefrorenem Code festlegen, G*Power
   für die Zahl der Experten und Themen, Kosten pro Modellfamilie.
4. **Matrix-Runner** für die 24 Zellen, danach die volle Matrix mit drei Modellfamilien.
5. **Sekundär:** Gender-Test mit zusätzlichen Seeds (96 Läufe, vorgeschlagen, nicht bestätigt) und eigener
   Bericht, falls er in die Arbeit gehört.

## 8. Fragen für die Besprechung

- Trägt der Tausch des Rollenetiketts als Kontrolle, oder will der Betreuer zusätzlich den neutralen Zusatztext
  nach Qu2026?
- Der blinde Judge zeigt +43,8 gegen +48,8. Heißt das, dass der Statuseffekt unter Quality vor allem ein
  Inhaltseffekt ist, oder ist die Variante zu undicht (Selbstbezüge, Namen in den Entwürfen), um das zu sagen?
  Soll auch der Inhalt der Entwürfe neutralisiert werden?
- Die Chatlänge-Studie empfiehlt 100 Turns, die letzten 80 Läufe haben 60. Sollen Sitztest und Gender-Test mit
  100 Turns wiederholt werden, oder genügt 60 mit Hinweis in den Limitationen?
- Der Orchestrator bevorzugt mittlere Sitze. Reicht Sitz als Kovariate, oder soll die Rotation vollständig
  (alle vier Sitze je Zelle) sein?
- Wie viele Wiederholungen pro Zelle (und Seeds) sind realistisch, und sollen Thema und Lauf als zufällige
  Effekte ins Mixed Model?
- Gehört der Gender-Test in die Arbeit (ein Hinweis, p ≈ 0,19) oder bleibt er ein Nebenzweig? Lohnen sich die
  zusätzlichen 96 Läufe?
- Soll die grobe Gebotsskala bei Score (1–5, 56 % Gleichstände) bleiben, um mit Tak2026 vergleichbar zu sein?
- Der Betreuer will das Modell festsetzen: Welches Modell ist es, bleibt es bei einem Modell oder gibt es weiter
  mehrere Familien, und was heißt das für H3 (Modell moderiert)?
