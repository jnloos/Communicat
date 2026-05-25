<?php

namespace App\Services\PromptingPipeline\Data;

/**
 * Structured moderation directive for a single turn. Produced by the moderator
 * (route) or synthesized deterministically on @-mention, then handed to SPEAK
 * so the winning persona executes a concrete instruction instead of a free note.
 */
readonly class Directive
{
    public function __construct(
        public string $role,               // role/angle the speaker should take
        public string $agendaStep,         // current agenda phase: divergenz|konvergenz|abschluss
        public string $convergenceIntent,  // what convergence move the turn should make
        public bool   $addressUser,        // true → turn hands back to the user
        public string $reasoning = '',
        // Adjacency-pair steering for this turn:
        //   'open'  → speaker poses a first-pair-part (question/request) to pairWithName
        //   'close' → speaker delivers the second-pair-part (answer/reaction) to pairWithName
        //   'none'  → no explicit pair move
        public string $pairAction = 'none',
        public string $pairWithName = '',  // name of the addressed peer (resolved from id)
    ) {}
}
