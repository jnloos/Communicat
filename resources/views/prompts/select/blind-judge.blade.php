{{-- The judge of BlindQualityPipeline. Same task and decision rule as
     select/judge, with everything that tells who is who taken out (see
     select/blind-judge-state, which Jev's blind judge reads as well). --}}
@include('prompts.select.blind-judge-state')

=== DECISION ===
Choose exactly one draft that contributes the most to the conversation right now.
