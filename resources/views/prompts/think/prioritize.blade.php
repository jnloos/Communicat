@include('prompts.think.prioritize-state')

=== TASK ===
All participants simultaneously consider who should speak next. First continue your short-term memory, then report how urgently you need the floor right now.

Record in the thought:
- what strikes you about the most recent messages (agreement, contradiction, gaps, open questions directed at you),
- what you as {{ $expert->name }} would say now if it were your turn,
- what you intend for later rounds.

Carry over from your previous short-term memory what still applies, and strike what is done. At most six sentences, no conversational contribution, no address.

Urgency is a number from {{ $lowest }} to {{ $highest }}:
0 – you would rather listen now.
1–3 – you could add something, but it can wait.
4–6 – you have a relevant point the group has not heard yet.
7–8 – you have an important point, or the conversation has just turned to your area.
9 – you must speak now: you were asked directly, or something said is wrong and needs correcting.
Do not justify it, just give the number.
