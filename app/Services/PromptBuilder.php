<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;

class PromptBuilder
{
    /**
     * PATH A — THINK only.
     * Returns a prompt asking the agent to output an updated Gedächtnis block.
     */
    public function think(Project $project, Expert $expert): string
    {
        $agents = $project->contributingExperts()
            ->mapWithKeys(fn($e) => [$e->id => ['name' => $e->name, 'job' => $e->job]])
            ->all();

        return $this->decode(view('prompts.agent.think', [
            'expert'   => $expert->asPromptArray($project),
            'project'  => $project->asPromptArray(),
            'agents'   => $agents,
        ])->render());
    }

    /**
     * PATH B — THINK+PRIORITIZE combined.
     * Returns a prompt asking the agent to output an updated Gedächtnis block plus a priority score.
     */
    public function thinkAndPrioritize(Project $project, Expert $expert): string
    {
        $agents = $project->contributingExperts()
            ->mapWithKeys(fn($e) => [$e->id => ['name' => $e->name, 'job' => $e->job]])
            ->all();

        return $this->decode(view('prompts.agent.think-prioritize', [
            'expert'   => $expert->asPromptArray($project),
            'project'  => $project->asPromptArray(),
            'agents'   => $agents,
        ])->render());
    }

    /**
     * SPEAK — winner agent only (both paths).
     * Returns a prompt asking the agent to generate a visible conversation turn.
     *
     * @param string $thinkOutput    The raw THINK or THINK+PRIORITIZE output from the preceding step.
     * @param string $moderationNote Optional moderation instruction injected by ModeratorService.
     */
    public function speak(Project $project, Expert $expert, string $thinkOutput, string $moderationNote = ''): string
    {
        $agents = $project->contributingExperts()
            ->mapWithKeys(fn($e) => [$e->id => ['name' => $e->name, 'job' => $e->job]])
            ->all();

        return $this->decode(view('prompts.agent.speak', [
            'expert'           => $expert->asPromptArray($project),
            'project'          => $project->asPromptArray(),
            'agents'           => $agents,
            'think_output'     => $thinkOutput,
            'moderation_note'  => $moderationNote,
        ])->render());
    }

    /**
     * MODERATOR ROUTE — Step 1.
     * Returns a prompt asking the moderator to decide PATH A or PATH B and select agents.
     *
     * @param array  $agents          Keyed by expert id → ['name', 'job'].
     * @param string $moderationNote  Optional moderation instruction from trigger checks.
     */
    public function moderatorRoute(Project $project, array $agents, string $moderationNote = '', ?string $directAddressHint = null): string
    {
        return $this->decode(view('prompts.moderator.route', [
            'project'             => $project->asPromptArray(),
            'agents'              => $agents,
            'moderation_note'     => $moderationNote,
            'direct_address_hint' => $directAddressHint,
        ])->render());
    }

    /**
     * MODERATOR SELECT — Step 3, PATH B only.
     * Returns a prompt asking the moderator to select the winning agent from THINK+PRIORITIZE outputs.
     *
     * @param array $agents                  Keyed by expert id → ['name', 'job'].
     * @param array $thinkPrioritizeOutputs  Keyed by agent name → raw THINK+PRIORITIZE output string.
     * @param array $state                   ['recent_speakers' => [...], 'recent_response_types' => [...]].
     */
    public function moderatorSelect(
        Project $project,
        array $agents,
        array $thinkPrioritizeOutputs,
        array $state,
        ?array $openAdjacencyPair = null,
    ): string {
        return $this->decode(view('prompts.moderator.select', [
            'project'                   => $project->asPromptArray(),
            'agents'                    => $agents,
            'think_prioritize_outputs'  => $thinkPrioritizeOutputs,
            'state'                     => $state,
            'open_adjacency_pair'       => $openAdjacencyPair,
        ])->render());
    }

    /**
     * SHORTEN CHAT — Summarizer only.
     * Returns a prompt asking the summarizer to compress a set of messages into a plain-text summary.
     *
     * @param array $messages  The oldest messages being compressed (not the full history).
     *                         Each entry: ['expert_id' => ..., 'name' => ..., 'content' => ...].
     */
    public function shortenChat(Project $project, array $messages): string
    {
        // Build a lightweight project array containing only the messages to compress,
        // rather than the full recent window from asPromptArray().
        $projectData = [
            'title'       => $project->title,
            'description' => $project->description,
            'messages'    => $messages,
        ];

        return $this->decode(view('prompts.shorten-chat', [
            'project' => $projectData,
        ])->render());
    }

    /**
     * @deprecated Use think() + speak() (PATH A) or thinkAndPrioritize() + speak() (PATH B) instead.
     *             This method remains functional until Stage 4 integration is complete.
     */
    public function nextMessage(Project $project, Expert $expert): string
    {
        return $this->decode(view('prompts.multiple.next-message', [
            'project' => $project->asPromptArray(),
            'expert'  => $expert->asPromptArray($project),
        ])->render());
    }

    /**
     * Blade's `{{ }}` HTML-escapes values, which turns apostrophes into &#039;
     * and ampersands into &amp; inside the rendered prompt. Some LLMs refuse
     * personas whose names look corrupted ("Devil&#039;s Advocate"), so we
     * decode entities once after rendering. Prompts are plain text, never HTML.
     */
    protected function decode(string $rendered): string
    {
        return html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
