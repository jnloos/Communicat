@if ($memory->longTerm !== '')
=== LANGZEITGEDÄCHTNIS (Zusammenfassung des bisherigen Gesprächs) ===
{{ $memory->longTerm }}

@endif
=== VERLAUF (jüngste Nachrichten) ===
@forelse ($memory->history as $entry)
{{ $entry['name'] }}{{ $entry['token'] ? ' ['.$entry['token'].']' : '' }}: {{ $entry['content'] }}
@empty
Noch keine Nachrichten.
@endforelse
@if ($showShortTerm ?? true)

=== DEIN KURZZEITGEDÄCHTNIS (nur für dich sichtbar) ===
{{ $memory->shortTerm !== '' ? $memory->shortTerm : 'Noch keine Gedanken notiert.' }}
@endif
