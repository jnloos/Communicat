{{-- What an agent sees before it is asked for its thought and its urgency: its
     persona, the topic, the participants and the memory. Shared by think/prioritize
     (the team model, which appends its TASK) and jev/bid (Jev, which appends the
     thought just written instead), so the view Jev bids from is the agent's own.
     $showShortTerm (default true) leaves the agent's short-term memory out for
     Jev: the thought it has just written replaces it there. --}}
@include('prompts.partials.persona', ['expert' => $expert, 'project' => $project])

@include('prompts.partials.project', ['project' => $project])

@include('prompts.partials.participants', ['experts' => $experts, 'project' => $project])

@include('prompts.partials.memory', ['memory' => $memory, 'showShortTerm' => $showShortTerm ?? true])
