# Avatar-Prompts für die 13 Studien-Personas

Stil-Referenz der vorhandenen Avatare (z. B. `Lisa_Graf.png`): gemaltes Digital-Portrait,
weicher Pinselstrich-Hintergrund in hellen Tönen, Person mittig, Brustbild, Blick zur Kamera,
warmes Licht. Zielformat: **1376×768 PNG**, Ablage als
`storage/app/public/avatars/static/Vorname_Nachname.png` (Umlaute transliteriert, z. B. `Juergen_Roth.png`).

Nach der Generierung (Quellbilder sind oft 1536×1024) **nicht verzerrend strecken**, sondern per Cover-Crop auf 1376×768 bringen:

```bash
magick input.png -resize 1376x768^ -gravity Center -extent 1376x768 output.png
```

Basis-Prompt (für alle, Rollenbeschreibung einsetzen):

> Painted digital portrait, soft visible brushstrokes, warm natural light, chest-up,
> looking at viewer with a calm friendly expression, softly blended beige-and-blue
> painterly background, muted realistic colors, professional illustration style —
> {PERSON}

| Datei | Person |
|---|---|
| `Verena_Albrecht.png` | German woman, shoulder-length dark blonde hair, blazer over blouse, competent warm HR manager look |
| `Robert_Steinbach.png` | German man, short gray-streaked hair, rimless glasses, shirt and sweater, sober business economist look |
| `Miriam_Tessmer.png` | German woman, brown hair in a loose bun, subtle earrings, dark turtleneck, thoughtful psychologist look |
| `Holger_Wittkamp.png` | German man, sturdy build, short gray hair, mustache, open work shirt, works-council chairman look |
| `Franziska_Ebert.png` | German woman, chin-length dark hair, architect-style glasses, dark jacket, urban planner look |
| `Juergen_Roth.png` | German man, thinning gray hair, friendly lined face, shirt with rolled-up sleeves, shop owner look |
| `Helena_Brueckner.png` | German woman, tied-back auburn hair, casual green jacket, energetic transport researcher look |
| `Norbert_Krause.png` | German man, full gray beard, kind eyes, cardigan over shirt, warm social-association representative look |
| `Beate_Sommerfeld.png` | German woman, short silver-gray hair, discreet necklace, blazer, headmistress look |
| `Tobias_Menzel.png` | German man, short brown hair, trimmed beard, casual shirt with hoodie, approachable media educator look |
| `Sandra_Petersen.png` | German woman, mid-length brown hair, everyday cardigan, warm busy working-mother look |
| `Leon_Jacobi.png` | German upper-secondary student, tousled dark blond hair, light acne, hoodie and headphones around neck, confident student-representative look |
| `Werner_Falk.png` | German man, weathered face, gray stubble, worn but tidy jacket and scarf, dignified direct gaze, lived-experience look |
