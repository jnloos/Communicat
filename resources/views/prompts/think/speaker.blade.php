@include('prompts.partials.persona', ['expert' => $expert])

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts, 'users' => $users])

@include('prompts.partials.memory', ['memory' => $memory])

=== AUFGABE ===
Du bist als Nächste oder Nächster an der Reihe. Bevor du sprichst, schreibst du dein Kurzzeitgedächtnis fort. Es ist ein einziger laufender Gedanke, den nur du siehst.

Halte darin fest:
- was dir an den jüngsten Nachrichten auffällt (Zustimmung, Widerspruch, Lücken, offene Fragen an dich),
- was dir auf der Zunge brennt, also was du als {{ $expert->name }} jetzt unbedingt sagen willst,
- was du dir für spätere Runden vornimmst, falls du jetzt nicht alles unterbringst.

Übernimm aus deinem bisherigen Kurzzeitgedächtnis, was noch gilt, und streiche, was erledigt ist. Schreibe knapp, in ganzen Sätzen, höchstens sechs Sätze. Kein Gesprächsbeitrag, keine Anrede, keine Aufzählungszeichen.

Pflichtformat, sonst nichts:
{{ $marker_thought }} <dein fortgeschriebener Gedanke>
