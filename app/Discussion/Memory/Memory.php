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
            ->with(['expert:id,name', 'user:id,name'])
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

    /** @return array{token: ?string, name: string, content: string} */
    public function describe(Message $message): array
    {
        $sender = $message->sender();

        return [
            'token' => $sender?->promptId,
            'name' => $sender?->name ?? 'System',
            'content' => $message->content,
        ];
    }

    private function thoughtOf(Project $project, Expert $expert): string
    {
        return (string) Summary::where('project_id', $project->id)
            ->where('expert_id', $expert->id)
            ->value('content');
    }
}
