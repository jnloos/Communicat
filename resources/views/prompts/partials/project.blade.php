{{-- The topic, and the frame every agent prompt shares.

     Two things this must not do. It must not name the project TITLE: that is
     the researcher's label for a cell -- "School self-selection with status" --
     and it would hand an agent the experimental condition it is in. And it must
     not say what kind of task this is. A topic may be a question to decide, a
     plan to draw up or anything else the description asks for; prescribing one
     shape here would contradict the others. The frame binds the agents to the
     topic and says nothing about what the topic wants. --}}
=== THE TOPIC ===
{{ $project->description ?: $project->title }}

This is what the group is here for. Stay with it: everything you contribute
serves this topic, and nothing else takes its place as the task.

If you think the group has arrived at a result, say what the result is and
test it against what you know -- what it costs, whom it fails, what you would
still have to find out to be sure of it -- instead of starting on something
new.
