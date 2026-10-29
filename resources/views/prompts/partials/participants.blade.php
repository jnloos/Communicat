@use('App\Discussion\Support\PersonaLines')
=== PARTICIPANTS (reference tokens) ===
@foreach ($experts as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}]{{ PersonaLines::for($project, $participant)->rosterLabel() }}
@endforeach
