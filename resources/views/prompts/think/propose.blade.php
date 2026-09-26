@include('prompts.partials.persona', ['expert' => $expert])

=== PROJECT ===
Title: {{ $project->title }}
@if (!empty($project->description))
Description: {{ $project->description }}
@endif

@include('prompts.partials.participants', ['experts' => $experts])

@include('prompts.partials.memory', ['memory' => $memory])

=== TASK ===
All participants simultaneously draft a contribution; afterwards an independent evaluation decides which draft gets spoken. First continue your short-term memory, then your draft.

Record in the thought:
- what strikes you about the most recent messages (agreement, contradiction, gaps, open questions directed at you),
- what you intend for later rounds.

Carry over from your previous short-term memory what still applies, and strike what is done. At most six sentences.

The draft is your discussion contribution, as you would say it: as {{ $expert->name }}, in complete sentences, without addressing the evaluation and without justifying why it is good.
