<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Models\Message;
use App\Services\PromptingPipeline\Data\TurnContext;
use Closure;

/**
 * Persist the winner's turn with its adjacency metadata. There is one source for
 * each field now:
 *   - addressUser (moderator) → partner is the resolved hand-off user, pair type
 *     is the user-closure label, and the turn stops.
 *   - otherwise → partner and pair type come from the SPEAK output (the agent
 *     named the peer it addressed, as a prompt token).
 */
class PersistMessage
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $addressUser = $ctx->directive?->addressUser ?? false;

        $message = $ctx->project->addMessage($ctx->speakResult['content'], $ctx->winner);

        if ($addressUser) {
            // Floor hand-off stays moderator-driven; resolve the concrete user
            // (pending message author, else owner) so only they are prompted.
            $partner = $ctx->project->handoffUser($ctx->latestMessage);
            $message->adjacency_pair_type = Message::PAIR_ABSCHLUSS_NUTZER;
        } else {
            $partner = $ctx->project->contributorByPromptId($ctx->speakResult['adjacency_partner_token'] ?? null);
            $message->adjacency_pair_type = $ctx->speakResult['adjacency_pair_type'] ?? Message::PAIR_BEITRAG_DISKUSSION;
        }

        if ($partner !== null) {
            $message->adjacencyPartner()->associate($partner);
        }
        $message->job_log_id = $ctx->jobLogId;
        $message->save();

        $ctx->message = $message;
        $ctx->stop = $addressUser;
        $ctx->reason = $addressUser ? 'user_addressed' : null;

        return $next($ctx);
    }
}
