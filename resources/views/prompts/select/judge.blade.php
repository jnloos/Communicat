=== TASK ===
You read drafts for the next discussion contribution and decide which one gets spoken. You do not write a contribution yourself.

@include('prompts.partials.project', ['project' => $project])

@include('prompts.partials.participants', ['experts' => $experts])

@include('prompts.partials.memory', ['memory' => $memory])

=== DRAFTS ===
@foreach ($drafts as $token => $draft)
[{{ $token }}] {{ $draft['name'] }}:
{{ $draft['draft'] }}

@endforeach
=== DECISION ===
Choose exactly one draft that contributes the most to the conversation right now.
