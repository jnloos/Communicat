<?php

namespace App\Pipeline\Stages;

use App\Models\Message;
use App\Pipeline\TurnContext;
use App\Services\ModeratorService;
use Closure;

/**
 * Persist the winner's turn with its UI metadata, and derive the turn's floor
 * outcome once here: floor authority belongs to the moderator, never the agent,
 * so next_speaker / adjacency_pair_type come from the Directive and the detected
 * open pair — never the speaking agent's output.
 */
class PersistMessage
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $addressUser = $ctx->directive?->addressUser ?? false;

        // Single source of truth for the floor outcome. The RunThink failure
        // guard short-circuits before this stage, so its reason is never reached
        // here.
        $ctx->nextSpeaker = $addressUser ? Message::USER_SENTINEL : null;
        $ctx->stop        = $addressUser;
        $ctx->reason      = $addressUser ? 'user_addressed' : null;

        $message = $ctx->project->addMessage($ctx->speakResult['content'], $ctx->winner);
        $message->adjacency_pair_type = $this->deriveAdjacencyPairType($ctx, $addressUser);
        $message->next_speaker        = $ctx->nextSpeaker;
        $message->job_log_id          = $ctx->jobLogId;
        $message->save();

        $ctx->message = $message;

        return $next($ctx);
    }

    /**
     * Prefer the explicit user hand-back, then the detected open pair, then a
     * default keyed on the agenda phase.
     */
    protected function deriveAdjacencyPairType(TurnContext $ctx, bool $addressUser): ?string
    {
        if ($addressUser) {
            return Message::PAIR_ABSCHLUSS_NUTZER;
        }

        $detected = $ctx->moderationContext['open_adjacency_pair']['pair_type'] ?? null;
        if (!empty($detected)) {
            return $detected;
        }

        return match ($ctx->directive?->agendaStep) {
            ModeratorService::AGENDA_PHASES[0] => Message::PAIR_BEITRAG_DISKUSSION,
            ModeratorService::AGENDA_PHASES[1] => Message::PAIR_SYNTHESE_DISKUSSION,
            ModeratorService::AGENDA_PHASES[2] => Message::PAIR_ABSCHLUSS_NUTZER,
            default                            => Message::PAIR_BEITRAG_DISKUSSION,
        };
    }
}
