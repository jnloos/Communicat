<?php

namespace App\Services\PromptingPipeline\Data;

/**
 * Structured moderation directive for a single turn. Produced by the moderator
 * (route), then handed to SPEAK so the winning persona executes a concrete
 * instruction instead of a free note. Adjacency-pair steering no longer lives
 * here: the speaking agent itself emits who it addresses (adjacency_partner)
 * and the pair type, parsed from the SPEAK output.
 */
readonly class Directive
{
    public function __construct(
        public string $role,               // role/angle the speaker should take
        public string $agendaStep,         // current agenda phase: divergenz|konvergenz|abschluss
        public string $convergenceIntent,  // what convergence move the turn should make
        public bool $addressUser,          // true → turn hands back to the user
        public string $reasoning = '',
        public ?string $pendingUserName = null,    // author of the pending (unanswered) user message
        public ?string $pendingUserExcerpt = null, // excerpt of that message, as shown to the moderator
    ) {}
}
