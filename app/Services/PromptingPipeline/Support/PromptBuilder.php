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
            ->mapWithKeys(fn ($e) => [$e->id => ['name' => $e->name, 'job' => $e->job, 'prompt_id' => $e->promptId]])
            ->all();

        // Every human participant gets its own [U<id>] memory block, so the
        // expert keeps a note per person (not one merged "user"), keyed by token.
        $users = $project->users()->get()
            ->push($project->owner)
            ->filter()
            ->unique('id')
            ->map(fn ($u) => ['name' => $u->name, 'prompt_id' => $u->promptId])
            ->values()
            ->all();

        return $this->decode(view('prompts.agent.think', [
            'expert' => $expert->asPromptArray($project),
            'project' => $project->asPromptArray(),
            'agents' => $agents,
            'users' => $users,
            'current_user_question' => $project->settings['current_user_question'] ?? null,
        ])->render());
    }

    /**
     * SPEAK — winning agent only.
     * Returns a prompt asking the agent to generate a visible turn that executes
     * the moderator's Directive in persona.
     *
     * @param  array{memory: string, beitragsabsicht: string}  $thinkOutput
     */
    public function speak(Project $project, Expert $expert, array $thinkOutput, Directive $directive): string
    {
        $contributors = $project->contributingExperts();

        $agents = $contributors
            ->mapWithKeys(fn ($e) => [$e->id => ['name' => $e->name, 'job' => $e->job, 'prompt_id' => $e->promptId]])
            ->all();

        // Human participants, so the agent can map a "U<id>" transcript label
        // back to a name for natural prose (the roster is the only token→name map).
        $users = $project->users()->get()
            ->push($project->owner)
            ->filter()
            ->unique('id')
            ->map(fn ($u) => ['name' => $u->name, 'prompt_id' => $u->promptId])
            ->values()
            ->all();

        $projectData = $project->asPromptArray();

        $participantNames = collect($agents)->pluck('name')
            ->concat(collect($users)->pluck('name'))
            ->flatMap(fn (string $name) => [$name, preg_split('/\s+/u', trim($name))[0] ?? $name])
            ->unique()
            ->values()
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
                'name' => $contributor->name,
                'openings' => array_slice(array_values($list), 0, 2),
            ];
        }

        $settings = $project->settings ?? [];

        return $this->decode(view('prompts.agent.speak', [
            'expert' => $expert->asPromptArray($project),
            'project' => $projectData,
            'agents' => $agents,
            'users' => $users,
            'think_output' => $thinkOutput,
            'directive' => $directive,
            'own_openings' => $ownOpenings,
            'other_openings' => $otherOpenings,
            'force_brevity' => $this->lastExpertTurnsAllLong($projectData['messages']),
            'forbid_name_opening' => $this->recentTurnsOpenWithName($projectData['messages'], $participantNames),
            'current_user_question' => $settings['current_user_question'] ?? null,
            'open_question' => $settings['open_question'] ?? null,
            'covered_points' => $settings['covered_points'] ?? [],
            'resolved_points' => $settings['resolved_points'] ?? [],
        ])->render());
    }

    /**
     * True when the last N expert turns were all long — the SPEAK prompt then
     * carries a hard brevity instruction to break long-message monotony.
     *
     * @param  array<int, array{prompt_id: ?string, content: string}>  $messages
     */
    protected function lastExpertTurnsAllLong(array $messages): bool
    {
        $streak = max(1, (int) config('discussion.brevity_streak', 3));
        $minChars = max(1, (int) config('discussion.brevity_min_chars', 200));

        $recent = array_slice($this->expertTurns($messages), -$streak);
        if (count($recent) < $streak) {
            return false;
        }

        foreach ($recent as $message) {
            if (mb_strlen(trim((string) ($message['content'] ?? ''))) < $minChars) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when one of the last two expert turns opened with a participant name
     * ("Bob, …") — the SPEAK prompt then forbids another name-first opening.
     *
     * @param  array<int, array{prompt_id: ?string, content: string}>  $messages
     * @param  string[]  $participantNames  full names and first names
     */
    protected function recentTurnsOpenWithName(array $messages, array $participantNames): bool
    {
        if (empty($participantNames)) {
            return false;
        }

        $recent = array_slice($this->expertTurns($messages), -2);
        if (empty($recent)) {
            return false;
        }

        $quoted = array_map(fn (string $name) => preg_quote($name, '/'), $participantNames);
        $pattern = '/^@?('.implode('|', $quoted).')\s*[,:]/iu';

        foreach ($recent as $message) {
            if (preg_match($pattern, ltrim((string) ($message['content'] ?? '')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{prompt_id: ?string, content: string}>  $messages
     * @return array<int, array{prompt_id: ?string, content: string}>
     */
    protected function expertTurns(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            fn (array $message) => str_starts_with((string) ($message['prompt_id'] ?? ''), 'E'),
        ));
    }

    /**
     * MODERATOR ROUTE — funnel step.
     * Returns a prompt asking the moderator to narrow the candidate pool and
     * emit the turn Directive (role, agenda step, convergence intent, address_user).
     *
     * @param  array  $agents  Keyed by expert id → ['name', 'job', 'prompt_id'].
     * @param  array{agenda_phase?: string, pending_user?: ?string}|null  $context
     *                                                                              Advisory signals: agenda phase, pending (unanswered) user excerpt.
     */
    public function moderatorRoute(Project $project, array $agents, string $moderationNote = '', ?array $context = null): string
    {
        return $this->decode(view('prompts.moderator.route', [
            'project' => $project->asPromptArray(),
            'agents' => $agents,
            'moderation_note' => $moderationNote,
            'moderation_context' => $context,
        ])->render());
    }

    /**
     * MODERATOR SELECT — winner step (only when more than one candidate).
     * Returns a prompt asking the moderator to qualitatively pick the best
     * contribution intent.
     *
     * @param  array  $agents  Keyed by expert id → ['name', 'job'].
     * @param  array<int, string>  $intents  expert id → BEITRAGSABSICHT text.
     * @param  array  $state  ['recent_speakers' => [expert id, ...], 'recent_response_types' => [...]].
     */
    public function moderatorSelect(
        Project $project,
        array $agents,
        array $intents,
        array $state,
    ): string {
        return $this->decode(view('prompts.moderator.select', [
            'project' => $project->asPromptArray(),
            'agents' => $agents,
            'intents' => $intents,
            'state' => $state,
        ])->render());
    }

    /**
     * MODERATOR CLOSURE — periodic progress/closure check (ProgressTracker).
     * Returns a prompt asking the moderator to judge whether the current point
     * is resolved or the discussion is circling, and to name the next move and
     * the remaining open question.
     */
    public function moderatorClosure(Project $project): string
    {
        $settings = $project->settings ?? [];

        return $this->decode(view('prompts.moderator.closure', [
            'project' => $project->asPromptArray(),
            'covered_points' => $settings['covered_points'] ?? [],
            'resolved_points' => $settings['resolved_points'] ?? [],
        ])->render());
    }

    /**
     * SHORTEN CHAT — Summarizer only.
     * Returns a prompt asking the summarizer to compress a set of messages into a plain-text summary.
     *
     * @param  array  $messages  The oldest messages being compressed (not the full history).
     *                           Each entry: ['expert_id' => ..., 'name' => ..., 'content' => ...].
     */
    public function shortenChat(Project $project, array $messages): string
    {
        // Build a lightweight project array containing only the messages to compress,
        // rather than the full recent window from asPromptArray().
        $projectData = [
            'title' => $project->title,
            'description' => $project->description,
            'messages' => $messages,
        ];

        return $this->decode(view('prompts.shorten-chat', [
            'project' => $projectData,
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
