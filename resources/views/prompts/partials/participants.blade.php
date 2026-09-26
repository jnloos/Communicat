=== PARTICIPANTS (reference tokens) ===
@foreach ($experts as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] ({{ $participant->role }})
@endforeach
@foreach ($users as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] (user)
@endforeach
