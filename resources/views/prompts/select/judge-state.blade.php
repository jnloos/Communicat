{{-- What the judge reads before it decides: the task, the topic, the participants,
     the shared memory and the drafts. Shared by select/judge (the team model, which
     appends its DECISION block) and jev/judge (Jev, whose decision travels as the
     question's instructions), so the two cannot drift. --}}
=== TASK ===
You read drafts for the next discussion contribution and decide which one gets spoken. You do not write a contribution yourself.

@include('prompts.partials.project', ['project' => $project])

@include('prompts.partials.participants', ['experts' => $experts, 'project' => $project])

@include('prompts.partials.memory', ['memory' => $memory])

=== DRAFTS ===
@foreach ($drafts as $token => $draft)
[{{ $token }}] {{ $draft['name'] }}:
{{ $draft['draft'] }}
@if (! $loop->last)

@endif
@endforeach
