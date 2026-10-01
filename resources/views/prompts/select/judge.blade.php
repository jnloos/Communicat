=== TASK ===
You read drafts for the next discussion contribution and decide which one gets spoken. You do not write a contribution yourself.

@include('prompts.partials.project', ['project' => $project])

@include('prompts.partials.memory', ['memory' => $memory])

=== DRAFTS ===
@foreach ($drafts as $token => $draft)
[{{ $token }}] {{ $draft['name'] }}:
{{ $draft['draft'] }}

@endforeach
=== DECISION ===
Choose exactly one draft: the one that advances the discussion most right now — a new argument, a solid number, an open question answered. Repetition, filler sentences and contributions that miss the topic advance it least. Evaluate the substance, not the person.
