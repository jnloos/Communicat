@include('prompts.partials.persona', ['expert' => $expert])

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts, 'users' => $users])

@include('prompts.partials.memory', ['memory' => $memory])

=== AUFGABE ===
Alle Beteiligten überlegen gleichzeitig, wer als Nächstes sprechen sollte. Schreibe zuerst dein Kurzzeitgedächtnis fort, dann melde, wie dringend du jetzt das Wort brauchst.

Halte im Gedanken fest:
- was dir an den jüngsten Nachrichten auffällt (Zustimmung, Widerspruch, Lücken, offene Fragen an dich),
- was du als {{ $expert->name }} jetzt sagen würdest, wenn du an der Reihe wärst,
- was du dir für spätere Runden vornimmst.

Übernimm aus deinem bisherigen Kurzzeitgedächtnis, was noch gilt, und streiche, was erledigt ist. Höchstens sechs Sätze, kein Gesprächsbeitrag, keine Anrede.

Die Dringlichkeit ist eine Zahl von {{ $lowest }} bis {{ $highest }}: {{ $lowest }}, wenn du nichts beizutragen hast, {{ $highest }}, wenn dein Beitrag jetzt unbedingt nötig ist. Begründe sie nicht, gib nur die Zahl.
