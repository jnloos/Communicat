<?php

namespace App\Discussion\Memory;

use App\Discussion\Values\MemoryView;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\Summary;

class Memory
{
    /**
     * Long-Term covers everything up to the project's watermark, History is
     * every participant message after it. The two never overlap. Without an
     * expert (selectors, summarizer) there is no private Short-Term layer.
     */
    public function viewFor(Project $project, ?Expert $expert = null): MemoryView
    {
        $watermark = $project->summarized_until_message_id ?? 0;

        // Where the visible window sits in the discussion as a whole. Numbering
        // the window from one would tell an agent "(1)" for the forty-first
        // contribution once the earlier ones have been folded into Long-Term.
        $alreadyFolded = $project->participantMessages()->where('id', '<=', $watermark)->count();

        $history = $project->participantMessages()
            ->where('id', '>', $watermark)
            ->with(['expert:id,name', 'user:id,name', 'addressee:id,name'])
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn (Message $message, int $offset) => $this->describe($message, $alreadyFolded + $offset + 1))
            ->all();

        return new MemoryView(
            history: $history,
            shortTerm: $expert === null ? '' : $this->thoughtOf($project, $expert),
            longTerm: (string) $project->long_term_memory,
        );
    }

    /**
     * One message as an agent reads it: who spoke, to whom, and what was said.
     *
     * The addressee travels with the message rather than only into the logs,
     * adapting Nonomura et al. (2025): making the recipient explicit helps an
     * agent see which adjacency pairs are open and which it is expected to
     * close, instead of inferring it from whether its name appears in the prose.
     *
     * It is omitted when a turn spoke to the group -- a missing arrow says that
     * unambiguously, and costs no tokens.
     *
     * A position is passed where the entry is read as part of a sequence -- the
     * history an agent sees. The summarizer folds entries into prose and needs
     * none, so it stays optional.
     *
     * @return array{position: ?int, token: ?string, name: string, content: string, addressee: ?array{token: string, name: string}}
     */
    public function describe(Message $message, ?int $position = null): array
    {
        $sender = $message->sender();
        $addressee = $message->addressee;

        return [
            'position' => $position,
            'token' => $sender?->promptId,
            'name' => $sender?->name ?? 'System',
            'content' => $message->content,
            'addressee' => $addressee === null ? null : [
                'token' => $addressee->promptId,
                'name' => $addressee->name,
            ],
        ];
    }

    private function thoughtOf(Project $project, Expert $expert): string
    {
        return (string) Summary::where('project_id', $project->id)
            ->where('expert_id', $expert->id)
            ->value('content');
    }
}
