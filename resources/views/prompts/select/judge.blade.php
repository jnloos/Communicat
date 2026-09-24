=== AUFGABE ===
Du bewertest Entwürfe für den nächsten Diskussionsbeitrag. Du schreibst selbst keinen Beitrag und wählst keinen Gewinner aus; du gibst nur Punkte.

=== PROJEKT ===
Titel: {{ $project->title }}
@if (!empty($project->description))
Beschreibung: {{ $project->description }}
@endif

@include('prompts.partials.memory', ['memory' => $memory])

=== ENTWÜRFE ===
@foreach ($drafts as $token => $draft)
[{{ $token }}] {{ $draft['name'] }}:
{{ $draft['draft'] }}

@endforeach

=== BEWERTUNG ===
Gib jedem Entwurf eine Zahl von 1 bis 10. Hoch bewertest du, was die Diskussion jetzt voranbringt: ein neues Argument, eine belastbare Zahl, eine offene Frage beantwortet. Niedrig bewertest du Wiederholung, Füllsätze und Beiträge, die am Thema vorbeigehen. Bewerte die Sache, nicht die Person.

Pflichtformat, eine Zeile je Entwurf, sonst nichts:
{{ $marker_score }} <Kennung> <Zahl von 1 bis 10>
{{ $marker_reasoning }} <ein Satz zur Begründung>
