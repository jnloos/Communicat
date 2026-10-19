@use('App\Discussion\Support\PersonaLines')
=== ABOUT YOU ===
You are {{ $expert->name }} (your token: {{ $expert->promptId }}).
Your role is {{ $expert->role }}.
@foreach (PersonaLines::for($project, $expert)->selfLines() as $line)
{{ $line }}
@endforeach

{{ $expert->description }}
