@use('App\Discussion\Support\PersonaLines')
=== PARTICIPANTS (reference tokens) ===
@foreach ($experts as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] ({{ $participant->role }}{{ PersonaLines::for($project, $participant)->rosterSuffix() }})
@endforeach
