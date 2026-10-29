@use('App\Discussion\Support\PersonaLines')
=== ABOUT YOU ===
You are {{ $expert->name }} (your token: {{ $expert->promptId }}).
@foreach (PersonaLines::for($project, $expert)->selfLines() as $line)
{{ $line }}
@endforeach

{{ $expert->description }}
