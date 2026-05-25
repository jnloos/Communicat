<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Project;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Data\Directive;

class ModeratorService
{
    /** Agenda phases, in order. The discussion mechanically advances through them. */
    public const AGENDA_PHASES = ['divergenz', 'konvergenz', 'abschluss'];

    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    /**
     * Derive a moderation note from mechanical, settings-based signals: silence
     * counters, and the agenda phase the discussion has reached. Returns a German
     * instruction string (empty if nothing to flag).
     */
    public function checkTriggers(): string
    {
        $settings        = $this->project->settings ?? [];
        $silenceCounters = $settings['silence_counters'] ?? [];
        $phase           = $this->agendaPhase();
        $turnsInPhase    = $settings['phase_turn_count'] ?? 0;

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

        // Agenda/convergence signal: nudge the discussion toward the next phase
        // once the current one has run for several turns.
        $notes[] = match ($phase) {
            'divergenz' => $turnsInPhase >= 4
                ? 'Genug Perspektiven gesammelt. Lenke die Diskussion von der Sammlung (Divergenz) hin zur Bündelung gemeinsamer Linien (Konvergenz).'
                : 'Phase Divergenz: Sammle weiterhin unterschiedliche Perspektiven und Argumente.',
            'konvergenz' => $turnsInPhase >= 4
                ? 'Die Konvergenz ist weit fortgeschritten. Steuere auf einen Abschluss/eine Synthese zu.'
                : 'Phase Konvergenz: Arbeite Gemeinsamkeiten heraus und gleiche Differenzen ab.',
            default => 'Phase Abschluss: Fasse die gemeinsame Position zusammen und schließe die Diskussion ab.',
        };

        return implode(' ', array_filter($notes));
    }

    /**
     * Ask the moderator LLM to narrow the candidate pool and define the turn's
     * Directive (role, agenda step, convergence intent, address_user).
     *
     * @param  array{open_adjacency_pair?: array, agenda_phase?: string, pending_user?: string}|null $context
     * @return array{candidates: string[], directive: Directive, reasoning: string}
     */
    public function route(string $moderationNote = '', ?array $context = null): array
    {
        $agents     = $this->buildAgentsArray();
        $knownNames = array_column(array_values($agents), 'name');

        $prompt   = $this->prompts->moderatorRoute($this->project, $agents, $moderationNote, $context);
        $response = $this->client->sendFast($prompt, 'moderator:route');

        $decoded = $this->parseJson($response);

        if ($decoded === null) {
            return [
                'candidates' => $knownNames,
                'directive'  => $this->fallbackDirective(),
                'reasoning'  => '',
            ];
        }

        $candidates = $this->normalizeKnownNames($decoded['candidates'] ?? $knownNames, $knownNames);
        if (empty($candidates)) {
            $candidates = $knownNames;
        }

        return [
            'candidates' => $candidates,
            'directive'  => $this->directiveFromArray($decoded['directive'] ?? [], (string) ($decoded['reasoning'] ?? '')),
            'reasoning'  => (string) ($decoded['reasoning'] ?? ''),
        ];
    }

    /**
     * Pick the winning candidate from the set of THINK outputs by qualitatively
     * judging their BEITRAGSABSICHT (no score). Back-to-back guard and the
     * open-adjacency-pair mandate are preserved.
     *
     * @param  array<string, array{memory: string, beitragsabsicht: string}> $thinkOutputs
     * @param  array{addressee: string, pair_type?: string, from?: string}|null $openAdjacencyPair
     */
    public function selectWinner(array $thinkOutputs, ?array $openAdjacencyPair = null): string
    {
        $agents = $this->buildAgentsArray();

        $state = [
            'recent_speakers'       => $this->project->settings['recent_speakers']       ?? [],
            'recent_response_types' => $this->project->settings['recent_response_types']  ?? [],
        ];

        // Expose only the contribution intents to the selection prompt.
        $intents = array_map(fn(array $o) => $o['beitragsabsicht'], $thinkOutputs);

        $prompt   = $this->prompts->moderatorSelect($this->project, $agents, $intents, $state, $openAdjacencyPair);
        $response = $this->client->sendFast($prompt, 'moderator:select');

        $decoded = $this->parseJson($response);

        if ($decoded === null || empty($decoded['winner'])) {
            return (string) array_key_first($thinkOutputs);
        }

        $winner = trim((string) $decoded['winner']);

        if (!array_key_exists($winner, $thinkOutputs)) {
            return (string) array_key_first($thinkOutputs);
        }

        // Hard back-to-back guard: never let the immediately previous speaker go
        // twice in a row unless the open-adjacency-pair path mandates it.
        $lastSpeaker = ($state['recent_speakers'][0] ?? null);
        $isMandated  = $openAdjacencyPair !== null
            && ($openAdjacencyPair['addressee'] ?? null) === $winner;

        if (!$isMandated && $lastSpeaker !== null && $winner === $lastSpeaker) {
            $alternatives = array_diff(array_keys($thinkOutputs), [$lastSpeaker]);
            if (!empty($alternatives)) {
                return (string) reset($alternatives);
            }
        }

        return $winner;
    }

    /**
     * Update project settings after a turn: recent speakers, response types,
     * silence counters, recent opening fragments, and the agenda phase.
     */
    public function updateState(Expert $winner, string $adjacencyType, string $content = ''): void
    {
        $settings = $this->project->settings ?? [];

        // Recent speakers — prepend winner, keep last 6
        $recentSpeakers = $settings['recent_speakers'] ?? [];
        array_unshift($recentSpeakers, $winner->name);
        $settings['recent_speakers'] = array_slice($recentSpeakers, 0, 6);

        // Recent response types — prepend type, keep last 6. The detected
        // adjacency-pair type feeds this rolling list directly.
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

        // Recent openings per expert — ring buffer of the last 3 own turns,
        // consumed by speak.blade.php to forbid reusing the same opener.
        $opening = $this->extractOpeningFragment($content);
        if ($opening !== '') {
            $recentOpenings = $settings['recent_openings'] ?? [];
            $perExpert      = $recentOpenings[$winner->id] ?? [];
            array_unshift($perExpert, $opening);
            $recentOpenings[$winner->id] = array_slice($perExpert, 0, 3);
            $settings['recent_openings'] = $recentOpenings;
        }

        // Agenda phase — advance mechanically: divergenz → konvergenz → abschluss
        // after PHASE_LENGTH turns each. Tracks turns spent in the current phase.
        $settings = $this->advanceAgenda($settings);

        $this->project->settings = $settings;
        $this->project->save();
    }

    /**
     * Current agenda phase from settings; defaults to the first phase.
     */
    public function agendaPhase(): string
    {
        $phase = $this->project->settings['agenda_phase'] ?? self::AGENDA_PHASES[0];
        return in_array($phase, self::AGENDA_PHASES, true) ? $phase : self::AGENDA_PHASES[0];
    }

    protected const PHASE_LENGTH = 5;

    /**
     * Increment the phase turn counter and roll over to the next agenda phase
     * once PHASE_LENGTH turns have elapsed. Abschluss is terminal.
     */
    protected function advanceAgenda(array $settings): array
    {
        $phase = $settings['agenda_phase'] ?? self::AGENDA_PHASES[0];
        if (!in_array($phase, self::AGENDA_PHASES, true)) {
            $phase = self::AGENDA_PHASES[0];
        }

        $turns = (int) ($settings['phase_turn_count'] ?? 0) + 1;

        $index = array_search($phase, self::AGENDA_PHASES, true);
        if ($turns >= self::PHASE_LENGTH && $index < count(self::AGENDA_PHASES) - 1) {
            $phase = self::AGENDA_PHASES[$index + 1];
            $turns = 0;
        }

        $settings['agenda_phase']     = $phase;
        $settings['phase_turn_count'] = $turns;

        return $settings;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Directive from the LLM's decoded `directive` object, tolerating
     * missing keys.
     */
    protected function directiveFromArray(array $d, string $reasoning): Directive
    {
        $phase = mb_strtolower(trim((string) ($d['agenda_step'] ?? $this->agendaPhase())));
        if (!in_array($phase, self::AGENDA_PHASES, true)) {
            $phase = $this->agendaPhase();
        }

        return new Directive(
            role:              (string) ($d['role'] ?? ''),
            agendaStep:        $phase,
            convergenceIntent: (string) ($d['convergence_intent'] ?? ''),
            addressUser:       (bool)   ($d['address_user'] ?? false),
            reasoning:         $reasoning,
        );
    }

    protected function fallbackDirective(): Directive
    {
        return new Directive(
            role:              '',
            agendaStep:        $this->agendaPhase(),
            convergenceIntent: '',
            addressUser:       false,
            reasoning:         '',
        );
    }

    /**
     * Extract the first ~10 words of the first non-empty line of a turn so the
     * next prompt can show the agent which openers are now off-limits.
     */
    protected function extractOpeningFragment(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $firstLine = trim(preg_split('/\r?\n/u', $content)[0] ?? '');
        if ($firstLine === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $firstLine) ?: [];
        $opener = implode(' ', array_slice($tokens, 0, 10));

        return mb_substr($opener, 0, 120);
    }

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
     * Match an LLM-provided name case-insensitively to the canonical DB name.
     *
     * @param array<int, string> $knownNames
     */
    protected function normalizeKnownName(?string $name, array $knownNames): ?string
    {
        $normalized = mb_strtolower(trim((string) $name));
        if ($normalized === '' || $normalized === 'null') {
            return null;
        }

        foreach ($knownNames as $knownName) {
            if (mb_strtolower($knownName) === $normalized) {
                return $knownName;
            }
        }

        return null;
    }

    /**
     * @param mixed $names
     * @param array<int, string> $knownNames
     * @return array<int, string>
     */
    protected function normalizeKnownNames(mixed $names, array $knownNames): array
    {
        if (!is_array($names)) {
            return $knownNames;
        }

        $normalized = [];
        foreach ($names as $name) {
            $knownName = $this->normalizeKnownName((string) $name, $knownNames);
            if ($knownName !== null && !in_array($knownName, $normalized, true)) {
                $normalized[] = $knownName;
            }
        }

        return $normalized;
    }

    /**
     * Parse JSON from a response that may be wrapped in a markdown code block.
     */
    protected function parseJson(string $response): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($response));
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        $decoded = json_decode(trim($cleaned), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback: grab the first JSON object anywhere in the string.
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
