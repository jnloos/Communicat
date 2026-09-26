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
        $history = $project->participantMessages()
            ->where('id', '>', $project->summarized_until_message_id ?? 0)
            ->with(['expert:id,name', 'user:id,name', 'addressee:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => $this->describe($message))
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
     * @return array{token: ?string, name: string, content: string, addressee: ?array{token: string, name: string}}
     */
    public function describe(Message $message): array
    {
        $sender = $message->sender();
        $addressee = $message->addressee;

        return [
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
