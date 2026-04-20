<?php

namespace App\Services;

use App\Models\Project;

class Summarizer
{
    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    /**
     * Compress old messages into a chat summary when the unsummarized buffer exceeds the threshold.
     * Advances the last_summarized_id watermark and stores the summary in project settings.
     */
    public function maybeRun(): void
    {
        $settings  = $this->project->settings ?? [];
        $threshold = $settings['buffer_threshold']   ?? 20;
        $keep      = $settings['buffer_keep']         ?? 8;
        $lastId    = $settings['last_summarized_id']  ?? 0;

        // Count unsummarized expert/user messages after the watermark
        $count = $this->project->messages()
            ->where(function ($q) {
                $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
            })
            ->where('id', '>', $lastId)
            ->count();

        if ($count <= $threshold) {
            return;
        }

        // Fetch the oldest (count - $keep) messages — these will be compressed
        $messagesToCompress = $this->project->messages()
            ->where(function ($q) {
                $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
            })
            ->where('id', '>', $lastId)
            ->oldest()
            ->take($count - $keep)
            ->get();

        if ($messagesToCompress->isEmpty()) {
            return;
        }

        $messagesArray = $messagesToCompress->map(fn($m) => $m->toPromptArray())->all();

        $prompt   = $this->prompts->shortenChat($this->project, $messagesArray);
        $response = $this->client->sendSlow($prompt);

        $settings['chat_summary']       = $response;
        $settings['last_summarized_id'] = $messagesToCompress->last()->id;

        $this->project->settings = $settings;
        $this->project->save();
    }
}
