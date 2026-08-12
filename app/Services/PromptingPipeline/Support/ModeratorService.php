<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Project;
use App\Services\Clients\OpenAIClient;
use App\Services\Clients\ResponseSchemas;
use App\Services\PromptingPipeline\Data\Directive;
use Illuminate\Support\Facades\Log;

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
        $settings = $this->project->settings ?? [];
        $silenceCounters = $settings['silence_counters'] ?? [];
        $phase = $this->agendaPhase();
        $turnsInPhase = $settings['phase_turn_count'] ?? 0;

        $notes = [];

        // Stale-topic trigger: the discussion has stayed on the same topic for a
        // long time without a registered advance. Reinforces the deterministic
        // force-advance in ProgressTracker with a textual nudge to the route LLM.
        $turnsOnTopic = (int) ($settings['turns_on_topic'] ?? 0);
        $staleThreshold = max(1, (int) config('discussion.topic_stale_threshold', 6));
        if ($turnsOnTopic >= $staleThreshold) {
            $notes[] = 'Das aktuelle Thema wird seit '.$turnsOnTopic.' Zügen ohne Themenwechsel diskutiert. Treibe aktiv einen Abschluss oder einen neuen Aspekt voran, statt denselben Punkt weiter zu vertiefen.';
        }

        // Silence trigger: any expert silent for >= 2 turns
        if (! empty($silenceCounters)) {
            $expertNames = $this->project->contributingExperts()
                ->mapWithKeys(fn (Expert $e) => [$e->id => $e->name])
                ->all();

            foreach ($silenceCounters as $expertId => $count) {
                if ($count >= 2 && isset($expertNames[$expertId])) {
                    $notes[] = 'Agent '.$expertNames[$expertId]
                        .' hat sich längere Zeit nicht geäußert. Beziehe ihn/sie aktiv in die Diskussion ein.';
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

        if ($this->project->descriptionIsSparse() && ! $this->project->hasUserMessage()) {
            $notes[] = 'Die Projektbeschreibung ist zu dünn oder fehlt. Lass die Experten nicht spekulieren, sondern steuere auf eine konkrete Klärungsfrage an den Nutzer (Ziel, Scope, Zielgruppe, Erfolgskriterium).';
        }

        return implode(' ', array_filter($notes));
    }

    /**
     * Ask the moderator LLM to narrow the candidate pool and define the turn's
     * Directive (role, agenda step, convergence intent, hand_back_to_user).
     *
     * @param  array{agenda_phase?: string, pending_user?: ?string}|null  $context
     * @return array{candidates: int[], directive: Directive, reasoning: string}
     */
    public function route(string $moderationNote = '', ?array $context = null): array
    {
        $agents = $this->buildAgentsArray();
        $knownIds = array_map('intval', array_keys($agents));

        $prompt = $this->prompts->moderatorRoute($this->project, $agents, $moderationNote, $context);
        $response = $this->client->sendFast($prompt, 'moderator:route', ResponseSchemas::route());

        $decoded = $this->parseJson($response);

        if ($decoded === null) {
            // With ResponseSchemas::route() attached this should not happen.
            // Log it: otherwise an unparseable answer is indistinguishable from
            // a legitimate "all candidates" routing decision.
            Log::warning('moderator:route returned unparseable JSON — falling back to all candidates', [
                'project_id' => $this->project->id,
                'response_first' => mb_substr($response, 0, 300),
            ]);

            return [
                'candidates' => $knownIds,
                'directive' => $this->attachPendingUser($this->decorateDirective($this->fallbackDirective(), $context), $context),
                'reasoning' => '',
            ];
        }

        // The moderator returns prompt tokens ("E7"); resolve them back to the
        // canonical integer expert ids the rest of the pipeline works with.
        $candidates = $this->resolveExpertIds($decoded['candidates'] ?? [], $knownIds);
        if (empty($candidates)) {
            $candidates = $knownIds;
        }

        return [
            'candidates' => $candidates,
            'directive' => $this->attachPendingUser($this->decorateDirective($this->directiveFromArray($decoded['directive'] ?? [], (string) ($decoded['reasoning'] ?? '')), $context), $context),
            'reasoning' => (string) ($decoded['reasoning'] ?? ''),
        ];
    }

    /**
     * Deterministic directive for the @-mention shortcut: the mentioned expert
     * answers the user's message directly — no route LLM call involved.
     *
     * @param  array{pending_user?: ?string, pending_user_name?: ?string}|null  $context
     */
    public function mentionDirective(?array $context = null): Directive
    {
        return $this->attachPendingUser(new Directive(
            role: 'frage_beantworten',
            agendaStep: $this->agendaPhase(),
            convergenceIntent: 'Direkt und konkret auf die letzte Nutzernachricht eingehen, bevor etwas Neues geöffnet wird.',
            handBackToUser: false,
            reasoning: 'Der Nutzer hat diesen Experten mit @ direkt angesprochen.',
        ), $context);
    }

    /**
     * Pick the winning candidate from the set of THINK outputs by qualitatively
     * judging their BEITRAGSABSICHT (no score). The hard back-to-back guard is
     * preserved as a deterministic guardrail against monologue loops.
     *
     * @param  array<int, array{memory: string, beitragsabsicht: string}>  $thinkOutputs  keyed by expert id
     * @param  bool  $allowBackToBack  bypass the guard (e.g. the user @-mentioned the last speaker)
     * @param  array{name: string, prompt_id: string, message_id?: int, question?: string}|null  $openFloor  addressee of an open pair
     * @return int the winning expert id
     */
    public function selectWinner(array $thinkOutputs, bool $allowBackToBack = false, ?array $openFloor = null): int
    {
        // Hard floor rule: someone who was asked a direct question answers it.
        // This used to be a prompt hint only, and the selection LLM regularly
        // walked past it — of eight closed pairs in the logged runs, exactly one
        // answered back to the asker. Enforced here for the same reason as the
        // back-to-back guard below: structure the transcript must show cannot
        // depend on the model choosing to comply.
        $floorWinner = $this->floorWinner($thinkOutputs, $openFloor, $allowBackToBack);

        if ($floorWinner !== null) {
            return $floorWinner;
        }

        $agents = $this->buildAgentsArray();

        $state = [
            'recent_speakers' => $this->project->settings['recent_speakers'] ?? [],
            'recent_response_types' => $this->project->settings['recent_response_types'] ?? [],
            'open_floor' => $openFloor,
        ];

        // Expose only the contribution intents to the selection prompt (keyed by id).
        $intents = array_map(fn (array $o) => $o['beitragsabsicht'], $thinkOutputs);

        $prompt = $this->prompts->moderatorSelect($this->project, $agents, $intents, $state);
        $response = $this->client->sendFast($prompt, 'moderator:select', ResponseSchemas::select());

        $decoded = $this->parseJson($response);

        if ($decoded === null || ! isset($decoded['winner'])) {
            return (int) array_key_first($thinkOutputs);
        }

        // The moderator returns a prompt token ("E7"); reduce it to the expert id.
        $winner = $this->promptIdToExpertId((string) $decoded['winner']);

        if ($winner === null || ! array_key_exists($winner, $thinkOutputs)) {
            return (int) array_key_first($thinkOutputs);
        }

        // Hard back-to-back guard: never let the immediately previous speaker go
        // twice in a row unless they are the only remaining candidate.
        $lastSpeaker = $state['recent_speakers'][0] ?? null;
        $lastSpeaker = $lastSpeaker !== null ? (int) $lastSpeaker : null;

        if (! $allowBackToBack && $lastSpeaker !== null && $winner === $lastSpeaker) {
            $alternatives = array_diff(array_keys($thinkOutputs), [$lastSpeaker]);
            if (! empty($alternatives)) {
                return (int) reset($alternatives);
            }
        }

        return $winner;
    }

    /**
     * The addressee of the oldest open pair, when they are able to answer now.
     *
     * Returns null — leaving the choice to the selection LLM — when there is no
     * open pair, the addressee did not think this turn, or answering would mean
     * speaking twice in a row (the back-to-back guard stays the outer rule, so a
     * persona never monologues just to close a pair).
     *
     * @param  array<int, array{memory: string, beitragsabsicht: string}>  $thinkOutputs
     * @param  array{prompt_id?: string}|null  $openFloor
     */
    protected function floorWinner(array $thinkOutputs, ?array $openFloor, bool $allowBackToBack): ?int
    {
        $addressee = $this->promptIdToExpertId((string) ($openFloor['prompt_id'] ?? ''));

        if ($addressee === null || ! array_key_exists($addressee, $thinkOutputs)) {
            return null;
        }

        $lastSpeaker = $this->project->settings['recent_speakers'][0] ?? null;

        if (! $allowBackToBack && $lastSpeaker !== null && (int) $lastSpeaker === $addressee) {
            return null;
        }

        return $addressee;
    }

    /**
     * Update project settings after a turn: recent speakers, response types,
     * silence counters, recent opening fragments, and the agenda phase.
     */
    public function updateState(Expert $winner, string $adjacencyType, string $content = '', string $beitragsabsicht = ''): void
    {
        $settings = $this->project->settings ?? [];

        // Progress/anti-circularity bookkeeping: fingerprint the turn, roll the
        // stagnation counter and the covered-points ledger, advance the
        // closure-check interval. (Deterministic, no LLM.)
        $settings = app(ProgressTracker::class, ['project' => $this->project])
            ->recordTurn($settings, $content, $beitragsabsicht);

        // Recent speakers — prepend winner id, keep last 6
        $recentSpeakers = $settings['recent_speakers'] ?? [];
        array_unshift($recentSpeakers, $winner->id);
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
            $perExpert = $recentOpenings[$winner->id] ?? [];
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
     * Advance the agenda by content first, by counter second. The phase rolls
     * forward when the closure check flagged the current phase as done
     * (`closure_advance`, set by ProgressTracker) OR, as a safety ceiling, once
     * PHASE_LENGTH turns have elapsed — so the discussion can't stall forever
     * even if the check keeps saying "vertiefen". Abschluss is terminal.
     */
    protected function advanceAgenda(array $settings): array
    {
        $phase = $settings['agenda_phase'] ?? self::AGENDA_PHASES[0];
        if (! in_array($phase, self::AGENDA_PHASES, true)) {
            $phase = self::AGENDA_PHASES[0];
        }

        $turns = (int) ($settings['phase_turn_count'] ?? 0) + 1;

        $forced = ! empty($settings['closure_advance']);
        $capReached = $turns >= self::PHASE_LENGTH;

        $index = array_search($phase, self::AGENDA_PHASES, true);
        if (($forced || $capReached) && $index < count(self::AGENDA_PHASES) - 1) {
            $phase = self::AGENDA_PHASES[$index + 1];
            $turns = 0;
        }

        // Consume the one-shot advance flag so it can't roll the phase again.
        unset($settings['closure_advance']);

        $settings['agenda_phase'] = $phase;
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
        if (! in_array($phase, self::AGENDA_PHASES, true)) {
            $phase = $this->agendaPhase();
        }

        return new Directive(
            role: Directive::normaliseRole($d['role'] ?? null),
            agendaStep: $phase,
            convergenceIntent: (string) ($d['convergence_intent'] ?? ''),
            handBackToUser: (bool) ($d['hand_back_to_user'] ?? false),
            reasoning: $reasoning,
        );
    }

    protected function fallbackDirective(): Directive
    {
        return new Directive(
            role: Directive::DEFAULT_ROLE,
            agendaStep: $this->agendaPhase(),
            convergenceIntent: '',
            handBackToUser: false,
            reasoning: '',
        );
    }

    /**
     * Apply hard handoff guards in priority order: topic clarification first
     * (sparse briefing), then cadence-based user inclusion. The pending-user
     * clamp sits outermost and can veto every hand-off decided below it.
     *
     * @param  array{pending_user?: ?string, user_inclusion_due?: bool, topic_clarification_due?: bool}|null  $context
     */
    protected function decorateDirective(Directive $directive, ?array $context): Directive
    {
        // Closure is innermost so the structural guards (sparse-briefing
        // clarification, then user-inclusion cadence) can still override its
        // verdict with a user hand-off when one is due.
        return $this->applyReactionCadence(
            $this->applyHandoffClamp(
                $this->applyUserInclusionGuard(
                    $this->applyTopicClarificationGuard(
                        $this->applyClosureGuard($directive, $context),
                        $context,
                    ),
                    $context,
                ),
                $context,
            ),
            $context,
        );
    }

    /**
     * Periodically turn a turn into a short reaction instead of another block of
     * exposition.
     *
     * The prompt has permitted brief agreement all along, and the round still
     * produced 58 straight informative turns — average 649 characters, exactly
     * one with any reaction marker. So the reaction is scheduled, not requested.
     *
     * Two conditions, both needed: the recent turns really were all long (the
     * existing brevity signal), and enough turns have passed since the last
     * reaction. Without the second, the brevity signal alone fired on 52 of 58
     * turns and would have made every turn a reaction.
     *
     * A hand-off is never converted — the user is owed a real question.
     *
     * @param  array{brevity_streak?: bool}|null  $context
     */
    protected function applyReactionCadence(Directive $directive, ?array $context): Directive
    {
        $cadence = max(0, (int) config('discussion.reaction_cadence', 3));

        if ($cadence === 0 || $directive->handBackToUser || $directive->role === 'kurz_reagieren') {
            return $directive;
        }

        if (empty($context['brevity_streak'])) {
            return $directive;
        }

        $since = (int) ($this->project->settings['turns_since_reaction'] ?? 0);

        if ($since < $cadence) {
            return $directive;
        }

        return new Directive(
            role: 'kurz_reagieren',
            agendaStep: $directive->agendaStep,
            convergenceIntent: $directive->convergenceIntent,
            handBackToUser: false,
            reasoning: $directive->reasoning,
            pendingUserName: $directive->pendingUserName,
            pendingUserExcerpt: $directive->pendingUserExcerpt,
        );
    }

    /**
     * Veto a hand-off the round cannot justify. Two deterministic cases:
     *
     *   1. An unanswered user message is pending. Answering "your turn" to
     *      someone who just asked something is a non-answer, so the experts
     *      must respond first. The three guards above already respect this;
     *      the route LLM did not — it set the flag in 18 of 18 logged turns,
     *      14 of them on top of a pending user message.
     *   2. The round handed back only a few expert turns ago. Without a
     *      cooldown the moderator chains hand-offs and the discussion never
     *      runs on its own.
     *
     * @param  array{pending_user?: ?string, handoff_cooldown_left?: int}|null  $context
     */
    protected function applyHandoffClamp(Directive $directive, ?array $context): Directive
    {
        if (! $directive->handBackToUser) {
            return $directive;
        }

        $pending = ! empty($context['pending_user']);
        $coolingDown = (int) ($context['handoff_cooldown_left'] ?? 0) > 0;

        if (! $pending && ! $coolingDown) {
            return $directive;
        }

        return new Directive(
            role: $pending ? 'frage_beantworten' : $directive->role,
            agendaStep: $directive->agendaStep,
            convergenceIntent: $pending
                ? 'Die offene Nutzernachricht konkret beantworten, bevor irgendetwas anderes geöffnet wird.'
                : $directive->convergenceIntent,
            handBackToUser: false,
            reasoning: $directive->reasoning,
            pendingUserName: $directive->pendingUserName,
            pendingUserExcerpt: $directive->pendingUserExcerpt,
        );
    }

    /**
     * When the periodic closure check fired, steer the turn: hand to the user if
     * a decision is needed, otherwise force convergence/closure so the debate
     * stops circling and drives to a Zwischenergebnis. A pending user message
     * still wins (answer it first).
     *
     * @param  array{closure_due?: bool, pending_user?: ?string, going_in_circles?: bool, point_resolved?: bool, next_move?: ?string, open_question?: ?string, zwischenergebnis?: ?string}|null  $context
     */
    protected function applyClosureGuard(Directive $directive, ?array $context): Directive
    {
        if (empty($context['closure_due']) || ! empty($context['pending_user'])) {
            return $directive;
        }

        $circling = ! empty($context['going_in_circles']);
        $resolved = ! empty($context['point_resolved']);
        $nextMove = $context['next_move'] ?? null;
        $openQuestion = trim((string) ($context['open_question'] ?? ''));
        $zwischen = trim((string) ($context['zwischenergebnis'] ?? ''));

        // "vertiefen" / "neuer_aspekt" with nothing resolved: no override — the
        // route directive already opens/deepens; the prompts carry the ledger.
        if (! $circling && ! $resolved && ! in_array($nextMove, ['abschluss', 'konvergenz', 'nutzer'], true)) {
            return $directive;
        }

        if ($nextMove === 'nutzer') {
            return new Directive(
                role: 'projektkontext_klaeren',
                agendaStep: $directive->agendaStep,
                convergenceIntent: $openQuestion !== ''
                    ? $openQuestion
                    : 'Eine konkrete Entscheidungs- oder Klärungsfrage an den Nutzer stellen.',
                handBackToUser: true,
                reasoning: $directive->reasoning,
            );
        }

        $step = ($resolved || $nextMove === 'abschluss') ? 'abschluss' : 'konvergenz';

        $intent = $step === 'abschluss'
            ? trim(($zwischen !== '' ? 'Knappes Zwischenergebnis festhalten: '.$zwischen.'. ' : 'Knappes Zwischenergebnis festhalten. ')
                .($openQuestion !== '' ? 'Danach die verbleibende offene Frage benennen: '.$openQuestion : 'Danach die verbleibende offene Frage benennen.'))
            : trim('Gemeinsamkeiten verdichten und auf eine Entscheidung hinarbeiten'
                .($openQuestion !== '' ? ', konkret zu: '.$openQuestion : '.'));

        return new Directive(
            role: $step === 'abschluss' ? 'zusammenfassen' : 'bruecke_bauen',
            agendaStep: $step,
            convergenceIntent: $intent,
            handBackToUser: $directive->handBackToUser,
            reasoning: $directive->reasoning,
        );
    }

    /**
     * When the project briefing is too thin and the user has not spoken yet,
     * force a clarifying handoff regardless of what the route LLM returned.
     *
     * @param  array{pending_user?: ?string, topic_clarification_due?: bool}|null  $context
     */
    protected function applyTopicClarificationGuard(Directive $directive, ?array $context): Directive
    {
        if (empty($context['topic_clarification_due']) || ! empty($context['pending_user'])) {
            return $directive;
        }

        return new Directive(
            role: 'projektkontext_klaeren',
            agendaStep: $directive->agendaStep,
            convergenceIntent: 'Eine konkrete Klärungsfrage zu Ziel, Scope, Zielgruppe oder Erfolgskriterium stellen — keine spekulative These.',
            handBackToUser: true,
            reasoning: $directive->reasoning,
        );
    }

    /**
     * When the expert-only cadence threshold is reached, force a user handoff
     * regardless of what the route LLM returned.
     *
     * @param  array{pending_user?: ?string, user_inclusion_due?: bool}|null  $context
     */
    protected function applyUserInclusionGuard(Directive $directive, ?array $context): Directive
    {
        if (empty($context['user_inclusion_due']) || ! empty($context['pending_user'])) {
            return $directive;
        }

        // Topic clarification already forced a handoff with a stronger intent.
        if ($directive->handBackToUser && $directive->role === 'projektkontext_klaeren') {
            return $directive;
        }

        return new Directive(
            role: $directive->role,
            agendaStep: $directive->agendaStep,
            convergenceIntent: $directive->convergenceIntent !== ''
                ? $directive->convergenceIntent
                : 'Eine konkrete Präferenz-, Klärungs- oder Freigabefrage an den Nutzer stellen.',
            handBackToUser: true,
            reasoning: $directive->reasoning,
        );
    }

    /**
     * Copy pending-user info from the moderation context onto the directive so
     * SPEAK can instruct the winner to answer the user's message first. Applied
     * as the last decoration step — it must not undo the inclusion guard.
     *
     * @param  array{pending_user?: ?string, pending_user_name?: ?string}|null  $context
     */
    protected function attachPendingUser(Directive $directive, ?array $context): Directive
    {
        if (empty($context['pending_user'])) {
            return $directive;
        }

        return new Directive(
            role: $directive->role,
            agendaStep: $directive->agendaStep,
            convergenceIntent: $directive->convergenceIntent,
            handBackToUser: $directive->handBackToUser,
            reasoning: $directive->reasoning,
            pendingUserName: $context['pending_user_name'] ?? 'Nutzer',
            pendingUserExcerpt: $context['pending_user'],
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
            ->mapWithKeys(fn (Expert $e) => [
                $e->id => ['name' => $e->name, 'job' => $e->job, 'prompt_id' => $e->promptId],
            ])
            ->all();
    }

    /**
     * Reduce a single "E7" prompt token to its integer expert id, or null if it
     * is malformed / not an expert token.
     */
    protected function promptIdToExpertId(string $token): ?int
    {
        return preg_match('/^E(\d+)$/', trim($token), $m) ? (int) $m[1] : null;
    }

    /**
     * Resolve a list of prompt tokens ("E7") to the canonical integer expert ids
     * that belong to this project, deduplicated and order-preserving. Anything
     * the LLM invents (unknown tokens, user tokens, names) is dropped.
     *
     * @param  int[]  $knownIds
     * @return int[]
     */
    protected function resolveExpertIds(mixed $tokens, array $knownIds): array
    {
        if (! is_array($tokens)) {
            return [];
        }

        $normalized = [];
        foreach ($tokens as $token) {
            $id = $this->promptIdToExpertId((string) $token);
            if ($id !== null && in_array($id, $knownIds, true) && ! in_array($id, $normalized, true)) {
                $normalized[] = $id;
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
