{{-- What the orchestrator reads before it decides: the task, the topic, the
     participants and the shared memory. Shared by select/orchestrator (the team
     model, which appends its DECISION block) and jev/orchestrator (Jev, whose
     decision travels as the question's instructions), so the two cannot drift. --}}
=== TASK ===
You are moderating a discussion and alone decide who speaks next. You do not speak yourself and give no instructions.

@include('prompts.partials.project', ['project' => $project])

@include('prompts.partials.participants', ['experts' => $experts, 'project' => $project])

@include('prompts.partials.memory', ['memory' => $memory])
