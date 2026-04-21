@props(['expert', 'project', 'agents' => []])
Du bist {{ $expert['name'] }}, {{ $expert['job'] }}.

=== BLOCK 1: PERSONA-KERN ===
{{ $expert['description'] }}

=== BLOCK 2: PROJEKTKONTEXT ===
Titel: {{ $project['title'] }}
@if (!empty($project['description']))
Beschreibung: {{ $project['description'] }}
@endif

=== BLOCK 3: DEIN AKTUELLES GEDÄCHTNIS ===
@if (!empty($expert['thoughts']->content))
{{ $expert['thoughts']->content }}
@else
Noch kein Gedächtnis vorhanden.
@endif

@if (!empty($project['chat_summary']))
=== GESPRÄCHSZUSAMMENFASSUNG (ältere Nachrichten): ===
{{ $project['chat_summary'] }}

@endif
=== AKTUELLE NACHRICHTEN: ===
@foreach ($project['messages'] as $message)
[{{ $message['name'] }}]: {{ $message['content'] }}
@endforeach

=== AUFGABE ===
Du hast soeben die aktuellen Nachrichten gelesen. Aktualisiere nun dein persönliches Gedächtnis.

Gib AUSSCHLIESSLICH den aktualisierten GEDÄCHTNIS-UPDATE-Block aus. Kein Gesprächstext, keine Begrüßung, keine Erklärung.

Pflichtformat:
GEDÄCHTNIS-UPDATE:
Was ich über den Nutzer weiß: ...
@foreach ($agents as $agentId => $agent)
@if ($agentId !== $expert['expert_id'])
Was ich über {{ $agent['name'] }} weiß: ...
@endif
@endforeach
Offene Fragen: ...
Letzter Gesprächsstand: ...
