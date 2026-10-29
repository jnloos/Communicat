{{-- What the blind judge reads before it decides: the task, the topic, the
     anonymous memory and the drafts under their letters. Shared by
     select/blind-judge (the team model, which appends its DECISION block) and
     jev/blind-judge (Jev, whose decision travels as the question's instructions),
     so the two cannot drift. Everything that tells who is who is taken out: no
     participant list, no names or tokens in the history, drafts under a
     shuffled letter instead of their author. What a draft or a contribution
     says about its author ("As CEO I propose ...") stays in -- that content
     pathway is exactly what this cell keeps measurable. --}}
=== TASK ===
You read drafts for the next discussion contribution and decide which one gets spoken. You do not write a contribution yourself.

@include('prompts.partials.project', ['project' => $project])

@include('prompts.partials.anonymous-memory', ['memory' => $memory])

=== DRAFTS ===
@foreach ($drafts as $label => $draft)
[{{ $label }}]
{{ $draft }}
@if (! $loop->last)

@endif
@endforeach
