<?php

namespace App\Services\PromptingPipeline\Data;

/**
 * Structured moderation directive for a single turn. Produced by the moderator
 * (route), then handed to SPEAK so the winning persona executes a concrete
 * instruction instead of a free note. Adjacency-pair steering does not live
 * here: the speaking agent itself emits who it addresses (adjacency_partner)
 * and the pair type, parsed from the SPEAK output.
 *
 * `handBackToUser` is the moderator's *intent* to pause the expert round and
 * wait for a human. It steers generation only — the binding decision is made in
 * PersistMessage, which reconciles it against what the visible text actually
 * does. The field was previously called `addressUser`, which both the LLM and
 * readers confused with "address a participant"; addressing an expert is never
 * a hand-back.
 */
readonly class Directive
{
    /**
     * Closed set of roles the route LLM may assign, mapped to the German
     * instruction rendered into the SPEAK prompt. Free-text roles drifted (the
     * LLM invented one-off values), so the token is normalised on the way in
     * and expanded to prose on the way out.
     */
    public const ROLES = [
        'frage_beantworten' => 'Die offene Frage direkt und inhaltlich beantworten; falls Information fehlt, genau eine gezielte Rückfrage im selben Beitrag stellen.',
        'kurz_reagieren' => 'In ein bis zwei Sätzen auf die zuletzt geäußerte Position reagieren — zustimmen, teilweise zustimmen oder begründet widersprechen. Kein neuer eigener Themenblock.',
        'zusammenfassen' => 'Den bisherigen Stand knapp verdichten und festhalten.',
        'advocatus_diaboli' => 'Die vorherrschende Position bewusst in Frage stellen und den stärksten Gegeneinwand vorbringen.',
        'beleg_fordern' => 'Für eine aufgestellte Behauptung einen konkreten Beleg, eine Zahl oder ein Beispiel einfordern.',
        'gegenposition' => 'Eine begründete Gegenposition zur zuletzt vertretenen These beziehen.',
        'bruecke_bauen' => 'Zwei auseinanderliegende Positionen zusammenführen und den gemeinsamen Nenner benennen.',
        'vertiefen' => 'Den aktuellen Punkt substanziell vertiefen statt ihn zu wiederholen.',
        'projektkontext_klaeren' => 'Den unklaren Projektkontext klären: Ziel, Scope, Zielgruppe, Erfolgskriterium oder Randbedingung.',
        'vorschlag_erklaeren' => 'Einen konkreten Vorschlag machen und ihn für Laien verständlich erklären.',
    ];

    public const DEFAULT_ROLE = 'vertiefen';

    public function __construct(
        public string $role,               // one of self::ROLES keys
        public string $agendaStep,         // current agenda phase: divergenz|konvergenz|abschluss
        public string $convergenceIntent,  // what convergence move the turn should make
        public bool $handBackToUser,       // moderator intent: pause the round and wait for a human
        public string $reasoning = '',
        public ?string $pendingUserName = null,    // author of the pending (unanswered) user message
        public ?string $pendingUserExcerpt = null, // excerpt of that message, as shown to the moderator
    ) {}

    /**
     * Coerce an arbitrary LLM value into a known role token. Unknown values fall
     * back to DEFAULT_ROLE rather than reaching the SPEAK prompt verbatim.
     */
    public static function normaliseRole(?string $role): string
    {
        $token = strtolower(trim((string) $role));

        return array_key_exists($token, self::ROLES) ? $token : self::DEFAULT_ROLE;
    }

    /**
     * The German instruction for this role, rendered into the SPEAK prompt.
     */
    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? self::ROLES[self::DEFAULT_ROLE];
    }
}
