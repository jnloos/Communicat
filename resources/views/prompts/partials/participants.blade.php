=== TEILNEHMER (Referenz-Tokens) ===
@foreach ($experts as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] ({{ $participant->role }})
@endforeach
@foreach ($users as $participant)
- {{ $participant->name }} [{{ $participant->promptId }}] (Nutzer)
@endforeach
Im sichtbaren Gespräch sprichst du Teilnehmer immer mit Namen an, niemals mit Token.
