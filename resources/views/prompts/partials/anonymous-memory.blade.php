{{-- The shared memory without the speakers: what was said, in order, but not
     who said it or to whom. For a reader that must judge content blind to
     status (the blind judge). The words themselves are left as they are, so a
     contribution that names someone or a title still does; so does the
     Long-Term summary, whose summarizer is told to refer to participants by
     name. --}}
@if ($memory->longTerm !== '')
=== LONG-TERM MEMORY (summary of the discussion so far) ===
{{ $memory->longTerm }}

@endif
=== HISTORY (most recent messages) ===
@forelse ($memory->history as $entry)
@if (! empty($entry['position'])){{ '(' . $entry['position'] . ') ' }}@endif{{ $entry['content'] }}
@empty
No messages yet.
@endforelse
