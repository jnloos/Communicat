{{-- The state Jev reads as blind judge: exactly what select/blind-judge shows the
     team model, because both include select/blind-judge-state (no participant
     list, no names or tokens in the history, drafts under a shuffled letter; what
     the words themselves say stays). Only the DECISION block is missing -- it
     travels as the question's instructions (JevBlindJudgeSelector::DECISION). --}}
@include('prompts.select.blind-judge-state')
