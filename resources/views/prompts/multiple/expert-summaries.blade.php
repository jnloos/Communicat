@props(['expert', 'project'])
{
    "prompt": "You are an analytical summarizer maintaining a living, per-expert brief for an ongoing technical project discussion. Your goal is to produce an updated brief for this expert that serves as their memory of the current chat. Use the full context to write a dense, third-person update that captures: the expert’s perceived objectives, recent decisions and rationale, discussed user stories, refined features, open questions, risks or blockers, dependencies, constraints/NFRs, and their immediate focus. Avoid implementation details entirely. Summaries must stay strictly at the level of user stories, feature opportunities, and requirements. Experts in the project interact naturally, often posing clarifying or challenging questions to each other. To preserve realism, no expert in the overall discussion contributes more than two times consecutively. Record this expert’s personal opinions, missed opportunities, and important information they must carry forward. Write compactly (2–5 sentences per block), in a neutral tone, without redundancy. Output must be JSON only.",

    "context": {
        "expert": @json($expert),
        "project": @json($project)
    },

    "output_schema": {
        "summary": "2–5 sentence, information-dense update covering objectives, decisions+rationale, user stories, open questions, risks/blockers, dependencies, constraints/NFRs, and the expert’s immediate focus.",
        "personal_opinions": "1–3 sentences capturing the expert’s explicit or inferred stance, concerns, or hypotheses.",
        "missed_opportunities": "1–2 sentences capturing points the expert wanted to raise, unanswered questions for others, or deferred topics.",
        "key_information": [
            "Concise bullets of crucial facts, tools, models, components, constraints, or metrics the expert must remember next."
        ]
    }
}
