<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Project;
use App\Services\PromptingPipeline\Data\Directive;

class PromptBuilder
{
    /**
     * THINK — memory update + contribution intent for one expert.
     * Returns a prompt asking the agent to output a GEDÄCHTNIS-UPDATE block and
     * a BEITRAGSABSICHT line.
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
     * SPEAK — winning agent only.
     * Returns a prompt asking the agent to generate a visible turn that executes
     * the moderator's Directive in persona.
     *
     * @param array{memory: string, beitragsabsicht: string} $thinkOutput
     */
    public function speak(Project $project, Expert $expert, array $thinkOutput, Directive $directive): string
    {
        $contributors = $project->contributingExperts();

        $agents = $contributors
            ->mapWithKeys(fn($e) => [$e->id => ['name' => $e->name, 'job' => $e->job]])
            ->all();

        // Recent opening fragments per expert (kept by ModeratorService::updateState).
        // Split into "own" (current expert) and "others" so the template can render
        // both blocks separately and forbid wholesale repetition of any opener that
        // appeared in the recent past — across all participants.
        $allOpenings = $project->settings['recent_openings'] ?? [];

        $ownOpenings = array_values($allOpenings[$expert->id] ?? []);

        $otherOpenings = [];
        foreach ($contributors as $contributor) {
            if ($contributor->id === $expert->id) {
                continue;
            }
            $list = $allOpenings[$contributor->id] ?? [];
            if (empty($list)) {
                continue;
            }
            // Only the most recent two openers per other expert.
            $otherOpenings[] = [
                'name'     => $contributor->name,
                'openings' => array_slice(array_values($list), 0, 2),
            ];
        }

        return $this->decode(view('prompts.agent.speak', [
            'expert'           => $expert->asPromptArray($project),
            'project'          => $project->asPromptArray(),
            'agents'           => $agents,
            'think_output'     => $thinkOutput,
            'directive'        => $directive,
            'own_openings'     => $ownOpenings,
            'other_openings'   => $otherOpenings,
        ])->render());
    }

    /**
     * MODERATOR ROUTE — funnel step.
     * Returns a prompt asking the moderator to narrow the candidate pool and
     * emit the turn Directive (role, agenda step, convergence intent, address_user).
     *
     * @param array $agents  Keyed by expert id → ['name', 'job'].
     * @param array{open_adjacency_pair?: array, agenda_phase?: string, pending_user?: string}|null $context
     *               Advisory signals: detected adjacency pair, agenda phase, pending user excerpt.
     */
    public function moderatorRoute(Project $project, array $agents, string $moderationNote = '', ?array $context = null): string
    {
        return $this->decode(view('prompts.moderator.route', [
            'project'            => $project->asPromptArray(),
            'agents'             => $agents,
            'moderation_note'    => $moderationNote,
            'moderation_context' => $context,
        ])->render());
    }

    /**
     * MODERATOR SELECT — winner step (only when more than one candidate).
     * Returns a prompt asking the moderator to qualitatively pick the best
     * contribution intent.
     *
     * @param array $agents  Keyed by expert id → ['name', 'job'].
     * @param array<string, string> $intents  agent name → BEITRAGSABSICHT text.
     * @param array $state   ['recent_speakers' => [...], 'recent_response_types' => [...]].
     */
    public function moderatorSelect(
        Project $project,
        array $agents,
        array $intents,
        array $state,
        ?array $openAdjacencyPair = null,
    ): string {
        return $this->decode(view('prompts.moderator.select', [
            'project'             => $project->asPromptArray(),
            'agents'              => $agents,
            'intents'             => $intents,
            'state'               => $state,
            'open_adjacency_pair' => $openAdjacencyPair,
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
     * @deprecated Legacy single-shot prompt used only by the old Assistant service.
     *             The funnel pipeline uses think() + speak() instead.
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
