<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;

class ModeratorService
{
    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    /**
     * Check project settings for moderation triggers.
     * Returns a German instruction string if any trigger fires, empty string otherwise.
     */
    public function checkTriggers(): string
    {
        $settings        = $this->project->settings ?? [];
        $silenceCounters = $settings['silence_counters'] ?? [];
        $topicTurnCount  = $settings['topic_turn_count'] ?? 0;

        $notes = [];

        // Silence trigger: any expert silent for >= 2 turns
        if (!empty($silenceCounters)) {
            $expertNames = $this->project->contributingExperts()
                ->mapWithKeys(fn(Expert $e) => [$e->id => $e->name])
                ->all();

            foreach ($silenceCounters as $expertId => $count) {
                if ($count >= 2 && isset($expertNames[$expertId])) {
                    $notes[] = 'Agent ' . $expertNames[$expertId]
                        . ' hat sich längere Zeit nicht geäußert. Beziehe ihn/sie aktiv in die Diskussion ein.';
                }
            }
        }

        // Topic stagnation trigger
        if ($topicTurnCount >= 5) {
            $notes[] = 'Die Diskussion dreht sich seit mehreren Turns ohne erkennbaren Fortschritt.'
                . ' Bringe einen neuen Aspekt oder einen Themenwechsel ein.';
        }

        return implode(' ', $notes);
    }

    /**
     * Ask the moderator LLM to decide PATH A or PATH B and which agents to involve.
     *
     * @return array{path: string, addressed_agent: string|null, selected_agents: array, reasoning: string}
     */
    public function route(string $moderationNote = ''): array
    {
        $agents = $this->buildAgentsArray();

        $prompt   = $this->prompts->moderatorRoute($this->project, $agents, $moderationNote);
        $response = $this->client->sendFast($prompt);

        $decoded = $this->parseJson($response);

        if ($decoded === null) {
            return [
                'path'            => 'B',
                'addressed_agent' => null,
                'selected_agents' => array_column(array_values($agents), 'name'),
                'reasoning'       => '',
            ];
        }

        $knownNames     = array_column(array_values($agents), 'name');
        $addressedAgent = isset($decoded['addressed_agent'])
            ? trim((string) $decoded['addressed_agent'])
            : null;

        // Reject addressed_agent if it isn't a known participant — fall back to PATH B
        if ($addressedAgent !== null && !in_array($addressedAgent, $knownNames, true)) {
            $addressedAgent = null;
        }

        return [
            'path'            => ($addressedAgent !== null) ? 'A' : ($decoded['path'] ?? 'B'),
            'addressed_agent' => $addressedAgent,
            'selected_agents' => $decoded['selected_agents'] ?? $knownNames,
            'reasoning'       => $decoded['reasoning']       ?? '',
        ];
    }

    /**
     * Ask the moderator LLM to pick the winner from a set of THINK+PRIORITIZE outputs.
     *
     * @param  array<string, string> $thinkPrioritizeOutputs  agent name → raw output
     */
    public function selectWinner(array $thinkPrioritizeOutputs): string
    {
        $agents = $this->buildAgentsArray();

        $state = [
            'recent_speakers'        => $this->project->settings['recent_speakers']        ?? [],
            'recent_response_types'  => $this->project->settings['recent_response_types']  ?? [],
        ];

        $prompt   = $this->prompts->moderatorSelect($this->project, $agents, $thinkPrioritizeOutputs, $state);
        $response = $this->client->sendFast($prompt);

        $decoded = $this->parseJson($response);

        if ($decoded === null || empty($decoded['winner'])) {
            return (string) array_key_first($thinkPrioritizeOutputs);
        }

        $winner = trim((string) $decoded['winner']);

        // Reject winner if it isn't among the candidates
        if (!array_key_exists($winner, $thinkPrioritizeOutputs)) {
            return (string) array_key_first($thinkPrioritizeOutputs);
        }

        return $winner;
    }

    /**
     * Update project settings after a turn: recent speakers, response types, silence counters.
     */
    public function updateState(Expert $winner, string $adjacencyType): void
    {
        $settings = $this->project->settings ?? [];

        // Recent speakers — prepend winner, keep last 6
        $recentSpeakers   = $settings['recent_speakers'] ?? [];
        array_unshift($recentSpeakers, $winner->name);
        $settings['recent_speakers'] = array_slice($recentSpeakers, 0, 6);

        // Recent response types — prepend type, keep last 6
        $recentTypes = $settings['recent_response_types'] ?? [];
        array_unshift($recentTypes, $adjacencyType);
        $settings['recent_response_types'] = array_slice($recentTypes, 0, 6);

        // Silence counters — increment all, then reset winner to 0
        $silenceCounters = $settings['silence_counters'] ?? [];
        foreach ($this->project->contributingExperts() as $expert) {
            $silenceCounters[$expert->id] = ($silenceCounters[$expert->id] ?? 0) + 1;
        }
        $silenceCounters[$winner->id] = 0;
        $settings['silence_counters'] = $silenceCounters;

        $this->project->settings = $settings;
        $this->project->save();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the agents array keyed by expert id → ['name', 'job'].
     */
    protected function buildAgentsArray(): array
    {
        return $this->project->contributingExperts()
            ->mapWithKeys(fn(Expert $e) => [$e->id => ['name' => $e->name, 'job' => $e->job]])
            ->all();
    }

    /**
     * Parse JSON from a response string that may be wrapped in a markdown code block.
     */
    protected function parseJson(string $response): ?array
    {
        // Strip markdown code fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($response));
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        $decoded = json_decode(trim($cleaned), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback: try to find a JSON object anywhere in the string
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
