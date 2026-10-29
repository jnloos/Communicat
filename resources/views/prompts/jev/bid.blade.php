{{-- The state Jev reads to bid for ONE agent: that agent's view, as in think/prioritize
     (both include think/prioritize-state: persona block with the project's attribute
     visibility, topic, participants, shared History and Long-Term) and the agent's own
     thought that was just written -- its short-term memory, not anybody else's, which
     is why the memory partial is asked to leave the stored short-term memory out. The
     think prompt's TASK block (which asks for the thought and the number) is left out:
     the number is Jev's to give. Written to the agent ("you"), like everything above. --}}
@include('prompts.think.prioritize-state', ['showShortTerm' => false])

=== YOUR THOUGHT RIGHT NOW (visible only to you) ===
{{ $thought }}
