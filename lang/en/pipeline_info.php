<?php

// Key is the short class name of the pipeline. Without an entry no info text is shown.
return [
    'RoundRobinPipeline' => 'The floor passes by seat in a fixed rotation after the last speaker. No model call decides who speaks; only the selected expert thinks before speaking. This is the null point for the share of speaking turns.',
    'ScorePipeline' => 'Every expert thinks first and bids a priority from 1 to 5 for the next turn. The highest bid speaks; ties are broken at random (reproducible from the project seed). The selection itself needs no model call.',
    'OrchestratorPipeline' => 'A model reads the history and the long-term summary and picks who speaks next. It sees the participant list but no private thoughts, and it never instructs the speaker. Only the picked expert thinks.',
    'QualityPipeline' => 'Every expert thinks and writes a draft contribution. A judge model sees the participant list and the drafts and picks the one that contributes the most right now; the author of that draft speaks.',
    'BlindQualityPipeline' => 'Like Quality, but the judge is blind: no participant list, no names or tokens, the drafts are labelled A–D and shuffled every turn. It separates a title the judge sees from status that only shows in the drafts.',
    'JevOrchestratorPipeline' => 'Like Orchestrator, but the next speaker is picked by Jev, a fixed decision model of TypeSafe, instead of the team model. Jev sees the same history, long-term summary and participant list and never a private thought. It is the same model for every team model.',
    'JevScorePipeline' => 'Like Score, but the 1 to 5 bid comes from Jev, a fixed decision model of TypeSafe, instead of from the agent. Every expert still writes its thought with the team model, which is also still asked for its own priority (only recorded); Jev then rates, from that expert\'s own view and the thought just written, how urgently it wants the floor. The highest bid speaks. Only the bid is independent of the team model, not the thought Jev reads.',
    'JevQualityPipeline' => 'Like Quality, but the draft is picked by Jev, a fixed decision model of TypeSafe, instead of the team model. Jev sees the participant list and the drafts, the same ones for every team model.',
    'JevBlindQualityPipeline' => 'Like Blind Quality, but the anonymous draft is picked by Jev, a fixed decision model of TypeSafe, instead of the team model: no participant list, no names or tokens, drafts labelled A–D and shuffled every turn.',
];
