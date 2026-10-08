{{-- The judge of BlindQualityPipeline. Same task and decision rule as
     select/judge, with everything that tells who is who taken out: no
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

@endforeach
=== DECISION ===
Choose exactly one draft that contributes the most to the conversation right now.
