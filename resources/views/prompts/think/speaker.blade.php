@include('prompts.partials.persona', ['expert' => $expert])

=== PROJECT ===
Title: {{ $project->title }}
@if (!empty($project->description))
Description: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts])

@include('prompts.partials.memory', ['memory' => $memory])

=== TASK ===
It is your turn next. Before you speak, you continue your short-term memory. It is a single running thought that only you see.

Record in it:
- what strikes you about the most recent messages (agreement, contradiction, gaps, open questions directed at you),
- what's on the tip of your tongue, i.e. what you as {{ $expert->name }} absolutely want to say now,
- what you intend for later rounds, in case you can't fit everything in now.

Carry over from your previous short-term memory what still applies, and strike what is done. Write concisely, in complete sentences, at most six sentences. No conversational contribution, no address, no bullet points.
