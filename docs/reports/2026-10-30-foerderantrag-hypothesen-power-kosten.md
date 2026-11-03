# Förderantrag: Hypothesen, Poweranalyse und Kostenschätzung (Entwurf)

Stand 30.10.2026. Alle Messwerte aus gpt-5-mini-Läufen, erste 30 gesprochene Turns. Das Modell für die Hauptstudie ist noch nicht entschieden; Abschnitt 3 stellt die Kosten je Modell gegenüber, damit sich daran entscheiden lässt.

## 1. Hypothesen

Zielgröße: Turn-Anteil der Fokus-Persona in der Diskussion (Beobachtungseinheit ist der Lauf, ein Paar sind zwei Läufe mit gleichem Thema, gleicher Pipeline und gleichem Seed, die sich nur in einer Bedingung unterscheiden). Ein Null- oder Gegeneffekt ist ein gültiges Ergebnis.

| | Hypothese | Kontrast (Paar) | Gruppe |
|---|---|---|---|
| H1 | Status: Mit Titel „CEO“ bekommt die Fokus-Persona mehr Turn-Anteil als ohne. | Titel gegen kein Titel | 4 Agenten |
| H2 | Gender: Der einzige Mann in einer Dreiergruppe bekommt einen anderen Turn-Anteil als derselbe Mann unter Männern. | ein Mann unter Frauen gegen alle Frauen | 3 Agenten |
| H3 | Herkunft: Der einzige Nigerianer bzw. die einzige Nigerianerin unter Deutschen bekommt einen anderen Turn-Anteil als unter Nigerianern. | eine Nigerianerin unter Deutschen gegen alle Deutschen | 3 Agenten |
| H4 | Mechanismus: Die Effekte aus H1 bis H3 unterscheiden sich zwischen Score, Orchestrator und Blind-Quality. | Interaktion Faktor × Pipeline | |

Bestätigend (nicht primär): die Spiegelkontraste „einzige Frau unter Männern“ (H2) und „einziger Deutscher unter Nigerianern“ (H3). Sie trennen den Faktor von „der Einzige sein“. Round Robin entfällt, dort ist der Turn-Anteil durch die Rotation fest. Quality ist gestrichen, Quality Blind bleibt.

## 2. Poweranalyse (G*Power-Äquivalent)

Gepaarter t-Test, zweiseitig, Power 0,80, Cohens dz = Effekt in Prozentpunkten geteilt durch die SD der Paardifferenzen. Primär sind 9 Tests (3 Faktoren × 3 Pipelines), also α = 0,05/9 = 0,0056 (Bonferroni). Mit den Spiegelkontrasten wären es 15 Tests, α = 0,0033.

**SD der Paardifferenzen, gemessen** (Turn-Anteil der Fokus-Persona, erste 30 gesprochene Turns, 53 Freiheitsgrade, gepoolt über alle Kontraste): **22,6 Prozentpunkte**. Obere Grenze des 80-%-Konfidenzintervalls (Browne, 1995): **24,8**. Pro Kontrast liegt sie zwischen 4 und 41, gestützt auf nur 4 bis 12 Paare; deshalb wird die obere Grenze verwendet. Der Pilotbericht vom 19.10. rechnete mit SD 40 (60 Turns, Quality und Sitz 3 enthalten); bei 30 Turns ohne Quality ist sie kleiner.

Benötigte Paare je Pipeline und Faktor (SD 24,8):

| kleinster relevanter Effekt | 9 Tests (α 0,0056) | 15 Tests (α 0,0033) |
|---|---|---|
| 10 Prozentpunkte | 85 | 92 |
| 15 Prozentpunkte | 40 | 44 |
| **20 Prozentpunkte** | **24** | **27** |

Mit der Punktschätzung SD 22,6: 71 / 34 / 21 Paare bei 9 Tests (10 / 15 / 20 pp).

Gerundet auf ein Vielfaches der 4 Themen: 24 Paare (20 pp), 40 Paare (15 pp), 88 Paare (10 pp).

**Gemessene Effekte** (Mittel der Paardifferenzen, 30 Turns, gpt-5-mini, je 4 bis 12 Paare): Status +21 bis +46 pp je Pipeline; Gender +3 bis +14 pp; Gender gespiegelt −15 bis +8 pp; Herkunft −4 bis +4 pp; Herkunft gespiegelt −8 bis +18 pp. Nur der Statuseffekt liegt klar über 20 pp. Eine Studie, die auf 20 pp ausgelegt ist, hätte für Gender und Herkunft keine Power, wenn die wahren Effekte so klein sind; ein nicht signifikantes Ergebnis wäre dort kein Nachweis von Gleichheit (dafür bräuchte es einen Äquivalenztest, Lakens et al., 2018). Wer für Gender und Herkunft kleinere Effekte nachweisen will, braucht die 15- oder 10-pp-Zeile.

Grenzen: nur 4 Themen, Thema als zufälliger Faktor nicht abgedeckt; Wiederholungen derselben Zelle weichen auch mit gleichem Seed stark ab (bis 45 pp pro Thema); H4 (Interaktion) braucht deutlich mehr Läufe als die Einzeltests und ist hier nicht gerechnet.

## 3. Kostenschätzung je Modell

### Token je Lauf (30 Turns, gemessen, gpt-5-mini, OpenAI-API)

| Pipeline | 4 Agenten (Status): Input / Output | 3 Agenten (Gender, Herkunft): Input / Output |
|---|---|---|
| Score | 998.896 / 63.022 | 788.501 / 51.434 |
| Orchestrator | 566.971 / 29.099 | 590.430 / 30.615 |
| Blind-Quality | 1.195.680 / 93.760 | 938.550 / 73.702 |

Output enthält die Reasoning-Tokens (gemessene Antworttexte plus Reasoning ergeben den gemeldeten Output). In neueren Läufen waren rund 72 % des Inputs gecacht; diese Quote wird für alle Modelle angenommen.

### Läufe

Je Paar zwei Läufe. Primär: 2 Läufe je Paar für Status, Gender und Herkunft. Mit Spiegelkontrasten: Gender und Herkunft haben 4 Läufe je Paar.

| Paare je Pipeline und Faktor | Läufe primär | Läufe mit Spiegelkontrasten |
|---|---|---|
| 24 (20 pp) | 432 | 720 |
| 40 (15 pp) | 720 | 1.200 |
| 88 (10 pp) | 1.584 | 2.640 |

### Preise je 1 Mio. Token (Input / gecachter Input / Output), US-Dollar

| Modell | Preis | Quelle, Anmerkung |
|---|---|---|
| gpt-5-mini | 0,25 / 0,025 / 2,00 | [OpenAI](https://developers.openai.com/api/docs/models/gpt-5-mini) |
| GPT-5.6 Luna | 0,20 / 0,02 / 1,20 | [Developers Digest](https://www.developersdigest.tech/models/gpt-5-6-luna), zitiert OpenAI vom 17.10.; andere Quellen nennen [1,00 / 0,10 / 6,00](https://www.orcarouter.ai/blog/gpt-5-6-api-pricing), ungeklärt |
| DeepSeek V4 Flash | 0,14 / 0,0028 / 0,28 | [DEV](https://dev.to/hassann/deepseek-v4-api-pricing-2j8f), mehrere Quellen übereinstimmend |
| DeepSeek V4 Pro | 1,74 / 0,145 / 3,48 | Cache-Preis zwischen Quellen 0,145 und 0,0145 strittig, hier der höhere Wert |
| Xiaomi MiMo V2.6 Pro | 0,435 / 0,0036 / 0,87 | [PricePerToken](https://pricepertoken.com/pricing-page/model/xiaomi-mimo-v2.6-pro) |
| Xiaomi MiMo V2.6 Flash | 0,14 / 0,0028 / 0,28 | [OrcaRouter](https://www.orcarouter.ai/providers/xiaomi) |

Alle Preise stammen aus Aggregatoren, bis auf gpt-5-mini; vor dem Antrag gegen die Herstellerseiten prüfen.

### Gesamtkosten der Hauptstudie (US-Dollar)

| Modell | 24 Paare, primär | 24 Paare, mit Spiegel | 40 Paare, primär | 40 Paare, mit Spiegel | 88 Paare, primär | 88 Paare, mit Spiegel |
|---|---|---|---|---|---|---|
| gpt-5-mini | 79 | 128 | 132 | 214 | 290 | 471 |
| Luna (0,20 / 1,20) | 54 | 87 | 89 | 145 | 197 | 320 |
| Luna (1,00 / 6,00) | 268 | 436 | 447 | 727 | 983 | 1.600 |
| DeepSeek V4 Flash | 21 | 35 | 36 | 58 | 78 | 127 |
| DeepSeek V4 Pro | 293 | 477 | 489 | 795 | 1.075 | 1.748 |
| MiMo V2.6 Pro | 65 | 106 | 108 | 176 | 238 | 387 |
| MiMo V2.6 Flash | 21 | 35 | 36 | 58 | 78 | 127 |

Zum Vergleich: Der Pilotbericht vom 19.10. kam bei 100 Turns und 768 Läufen auf 1.312 US-Dollar. Der Unterschied kommt von 30 statt 100 Turns, vom Wegfall von Quality und von der Berücksichtigung des Cache.

### Grenzen der Kostenzahlen

- Die Token-Mengen stammen von gpt-5-mini. Andere Modelle schreiben längere oder kürzere Antworten und denken unterschiedlich lang; Reasoning-Modelle können den Output vervielfachen. Die Zahlen für DeepSeek und MiMo sind deshalb Preisvergleiche bei gleicher Tokenzahl, keine Messung. Eine Probe von je 8 bis 16 Läufen pro Modell würde die Tokenzahl messen.
- DeepSeek und MiMo sind in der App noch nicht angebunden (je ein Provider-Eintrag in `config/ai.php`, vermutlich OpenAI-kompatibel).
- Luna läuft derzeit über ein Gateway mit ChatGPT-Konto, nicht über die API; für den Antrag nur mit API-Preisen ansetzen.
- Nicht enthalten: Wiederholungen, Fehlläufe (aktuell etwa 0,3 %), Pilot- und Testläufe, Jev-Varianten.
- Die Wortlaut-Probe „ohne das Wort Rolle“ (Projekte 429 bis 460, 24 Zellen) läuft noch; ihr Ergebnis fließt nicht in diese Zahlen ein.
