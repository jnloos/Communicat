@if ($memory->longTerm !== '')
=== LONG-TERM MEMORY (summary of the discussion so far) ===
{{ $memory->longTerm }}

@endif
=== HISTORY (most recent messages) ===
@forelse ($memory->history as $entry)
{{ $entry['name'] }}{{ $entry['token'] ? ' ['.$entry['token'].']' : '' }}: {{ $entry['content'] }}
@empty
No messages yet.
@endforelse
@if ($showShortTerm ?? true)

=== YOUR SHORT-TERM MEMORY (visible only to you) ===
{{ $memory->shortTerm !== '' ? $memory->shortTerm : 'No thoughts noted yet.' }}
@endif
