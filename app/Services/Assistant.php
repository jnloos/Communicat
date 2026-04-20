<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

class Assistant
{
    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    public static function forProject(Project $project): static {
        return new static($project, new OpenAIClient(), new PromptBuilder());
    }

    public function genExpertSummaries(): void {
        $freq             = $this->project->settings['summary_frequency'] ?? 10;
        $lastSummarizedId = $this->project->settings['last_summarized_id'] ?? 0;

        // Only summarize the oldest N unsummarized messages
        $oldestMessages = $this->project->messages()
            ->where(function ($q) {
                $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
            })
            ->where('id', '>', $lastSummarizedId)
            ->oldest()
            ->take($freq)
            ->get();

        if ($oldestMessages->isEmpty()) {
            return;
        }

        $newLastId = $oldestMessages->last()->id;

        // Build snapshot of only those oldest messages for the prompt
        $projectSnapshot = [
            'title'       => $this->project->title,
            'description' => $this->project->description,
            'messages'    => $oldestMessages->map(fn($msg) => $msg->toPromptArray())->values()->all(),
        ];

        $experts = $this->project->contributingExperts();
        $indexed = $experts->values();

        $prompts = $indexed->map(fn(Expert $e) => view('prompts.multiple.expert-summaries', [
            'project' => $projectSnapshot,
            'expert'  => $e->asPromptArray($this->project),
        ])->render())->all();

        $responses = $this->client->sendMany($prompts);

        foreach ($indexed as $i => $expert) {
            $json = $responses[$i] ?? null;
            if (!$json) continue;

            $decoded = json_decode($json, true);
            if (empty($decoded)) continue;

            $summary          = $expert->thoughtsAbout($this->project);
            $summary->content = $decoded;
            $summary->save();
        }

        // Advance the summarized watermark
        $settings                      = $this->project->settings ?? [];
        $settings['last_summarized_id'] = $newLastId;
        $this->project->settings       = $settings;
        $this->project->save();
    }

    /** @deprecated Use PipelineModerator::run() instead */
    public function genNextMessage(): void {
        $experts = $this->project->contributingExperts();
        $indexed = $experts->values();

        $prompts   = $indexed->map(fn(Expert $e) => $this->prompts->nextMessage($this->project, $e))->all();
        $responses = $this->client->sendMany($prompts);

        $messages = [];
        foreach ($responses as $response) {
            $decoded = json_decode($response, true);
            foreach ((array) $decoded as $expertId => $data) {
                if (!empty($data)) {
                    $messages[$expertId] = $data;
                }
            }
        }

        if (empty($messages)) {
            return;
        }

        Log::info(json_encode($messages, JSON_PRETTY_PRINT));

        uasort($messages, fn($a, $b) => ($b['importance'] ?? 0) <=> ($a['importance'] ?? 0));

        $importantExpert  = array_key_first($messages);
        $importantContent = $messages[$importantExpert] ?? null;

        if ($importantContent && isset($importantContent['statement'])) {
            $expert = Expert::find($importantExpert);
            if ($expert) {
                $this->project->addMessage($importantContent['statement'], $expert);
            }
        }

        if ($this->needsSummariesRefresh()) {
            $this->genExpertSummaries();
        }

        foreach ($indexed as $expert) {
            $thought = $expert->thoughtsAbout($this->project);

            if ($expert->id === $importantExpert && isset($importantContent['statement'])) {
                $thought->content .= "\n\n" . $expert->name . " was able to contribute: " . $importantContent['statement'];
            } elseif (isset($messages[$expert->id]['statement'])) {
                $thought->content .= "\n\n" . $expert->name . " wanted to contribute: " . $messages[$expert->id]['statement'];
            }

            $thought->save();
        }
    }

    protected function needsSummariesRefresh(): bool {
        $freq             = $this->project->settings['summary_frequency'] ?? 10;
        $lastSummarizedId = $this->project->settings['last_summarized_id'] ?? 0;

        $unsummarized = $this->project->messages()
            ->where(function ($q) {
                $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
            })
            ->where('id', '>', $lastSummarizedId)
            ->count();

        return $unsummarized >= $freq;
    }
}
