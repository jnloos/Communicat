@include('prompts.partials.persona', ['expert' => $expert])

=== PROJECT ===
Title: {{ $project->title }}
@if (!empty($project->description))
Description: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts])

@include('prompts.partials.memory', ['memory' => $memory])

=== TASK ===
All participants simultaneously consider who should speak next. First continue your short-term memory, then report how urgently you need the floor right now.

Record in the thought:
- what strikes you about the most recent messages (agreement, contradiction, gaps, open questions directed at you),
- what you as {{ $expert->name }} would say now if it were your turn,
- what you intend for later rounds.

Carry over from your previous short-term memory what still applies, and strike what is done. At most six sentences, no conversational contribution, no address.

Urgency is a number from {{ $lowest }} to {{ $highest }}: {{ $lowest }} if you have nothing to contribute, {{ $highest }} if your contribution is absolutely necessary right now. Do not justify it, just give the number.
