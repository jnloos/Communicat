<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Project;
use App\Services\Clients\OpenAIClient;

class Summarizer
{
    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    /**
     * Compress old messages into a chat summary. Runs ONLY when the number of
     * unsummarized expert/user messages EXCEEDS summary_trigger_at; then every
     * message except the newest summary_keep_recent is compressed into the
     * rolling chat summary, advancing the last_summarized_id watermark.
     *
     * Resolution order per knob: project.settings (new key, then legacy key) →
     * config('discussion.*'). Legacy keys: buffer_threshold / buffer_keep.
     */
    public function maybeRun(): void
    {
        $settings  = $this->project->settings ?? [];
        $threshold = $settings['summary_trigger_at']
            ?? $settings['buffer_threshold']
            ?? config('discussion.summary_trigger_at', 100);
        $keep      = $settings['summary_keep_recent']
            ?? $settings['buffer_keep']
            ?? config('discussion.summary_keep_recent', 30);
        $lastId    = $settings['last_summarized_id'] ?? 0;

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
        $response = $this->client->sendSlow($prompt, 'summarizer:shorten-chat');

        $settings['chat_summary']       = $response;
        $settings['last_summarized_id'] = $messagesToCompress->last()->id;

        $this->project->settings = $settings;
        $this->project->save();
    }
}
