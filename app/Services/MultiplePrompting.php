<?php
namespace App\Services;

use App\Models\Expert;
use App\Services\Dependencies\PromptingStrategy;
use Illuminate\Support\Facades\Log;

class MultiplePrompting extends PromptingStrategy
{
    public function genExpertSummaries(): void {
        // Prepare data
        $experts = $this->project->contributingExperts();
        $prompts = [];
        foreach ($experts as $expert) {
            $params = [
                'project' => $this->project->asPromptArray(),
                'expert'  => $expert->asPromptArray($this->project),
            ];
            $prompts[$expert->id] = view('prompts.multiple.expert-summaries', $params)->render();
        }

        // Run all prompts concurrently
        $responses = $this->sendPrompts(array_values($prompts));

        // Store fresh summaries for each expert
        $responses = array_combine(array_keys($prompts), $responses);
        foreach ($responses as $expertId => $json) {
            $response = json_decode($json, true);

            if (empty($response)) {
                continue;
            }

            $expert = Expert::find($expertId);
            if (!$expert) {
                continue;
            }

            $summary = $expert->thoughtsAbout($this->project);
            $summary->content = $response;
            $summary->save();
        }
    }

    public function genNextMessage(): void {
        // Prepare data
        $experts = $this->project->contributingExperts();
        $prompts = [];
        foreach ($experts as $expert) {
            $params = [
                'project' => $this->project->asPromptArray(),
                'expert'  => $expert->asPromptArray($this->project),
            ];
            $prompts[$expert->id] = view('prompts.multiple.next-message', $params)->render();
        }

        // Run all prompts concurrently
        $responses = $this->sendPrompts($prompts);

        $messages = [];
        foreach ($responses as $response) {
            $response = json_decode($response, true);
            // Unpacking loop always unpacks only one tuple
            foreach ($response as $expertId => $data) {
                if (!empty($data)) {
                    $messages[$expertId] = $data;
                }
            }
        }

        if (empty($messages)) {
            return;
        }

        Log::info(json_encode($messages, JSON_PRETTY_PRINT));

        // Sort by importance
        uasort($messages, fn($a, $b) => ($b['importance'] ?? 0) <=> ($a['importance'] ?? 0));

        // Determine the most important message and store it
        $importantExpert  = array_key_first($messages);
        $importantContent = $messages[$importantExpert] ?? null;
        if ($importantContent && isset($importantContent['statement'])) {
            $expert = Expert::find($importantExpert);
            if ($expert) {
                $this->project->addMessage($importantContent['statement'], contributor: $expert);
            }
        }

        // Refresh summaries if needed
        if ($this->needsExpertSummariesRefresh()) {
            $this->genExpertSummaries();
        }

        // Store thoughts for each expert
        foreach ($experts as $expert) {
            $thought = $expert->thoughtsAbout($this->project);

            if ($expert->id === $importantExpert && isset($importantContent['statement'])) {
                $thought->content .= "\n\n" . $expert->name . " was able to contribute: " . $importantContent['statement'];
            }
            elseif (isset($messages[$expert->id]['statement'])) {
                $thought->content .= "\n\n" . $expert->name . " wanted to contribute: " . $messages[$expert->id]['statement'];
            }

            $thought->save();
        }
    }
}
