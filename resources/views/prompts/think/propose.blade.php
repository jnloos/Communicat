@include('prompts.partials.persona', ['expert' => $expert])

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts, 'users' => $users])

@include('prompts.partials.memory', ['memory' => $memory])

=== AUFGABE ===
Alle Beteiligten entwerfen gleichzeitig einen Beitrag; anschließend entscheidet eine unabhängige Bewertung, welcher Entwurf gesprochen wird. Schreibe zuerst dein Kurzzeitgedächtnis fort, dann deinen Entwurf.

Halte im Gedanken fest:
- was dir an den jüngsten Nachrichten auffällt (Zustimmung, Widerspruch, Lücken, offene Fragen an dich),
- was du dir für spätere Runden vornimmst.

Übernimm aus deinem bisherigen Kurzzeitgedächtnis, was noch gilt, und streiche, was erledigt ist. Höchstens sechs Sätze.

Der Entwurf ist dein Diskussionsbeitrag, wie du ihn sagen würdest: als {{ $expert->name }}, in ganzen Sätzen, ohne Anrede an die Bewertung und ohne Begründung, warum er gut sei.

Pflichtformat, sonst nichts:
{{ $marker_thought }} <dein fortgeschriebener Gedanke>
{{ $marker_draft }} <dein Entwurf>
