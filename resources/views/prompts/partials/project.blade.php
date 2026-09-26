{{-- The question, and the frame every agent prompt shares.

     The project TITLE is deliberately not here. It is the researcher's label
     for a cell -- "School self-selection with status" -- and showing it to an
     agent would hand it the experimental condition it is in. The description is
     the topic; it is what the group was convened to answer. --}}
=== THE QUESTION ===
{{ $project->description ?: $project->title }}

You are deliberating this question, not carrying anything out. There is no
rollout to plan, no task to hand out, no schedule, no material to produce and
nothing to approve. Every contribution serves answering the question.

If the group has converged, say what the shared answer is and test it against
what you know: what it costs, whom it fails, what you would still have to find
out to be sure of it.
