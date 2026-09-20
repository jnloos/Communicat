<?php

namespace App\Discussion\Stages;

use App\Discussion\TurnPayload;
use App\Models\Expert;
use App\Models\Message;
use Closure;

/** Saves the public contribution. Needs SelectSpeaker and Speak before it. */
class PersistMessage
{
    public function handle(TurnPayload $payload, Closure $next)
    {
        $contribution = $payload->contribution();

        $message = $payload->project->addMessage($contribution->text, $payload->selection()->speaker);
        $message->adjacency_pair_type = $contribution->pairType ?? Message::PAIR_BEITRAG_DISKUSSION;
        $message->job_log_id = $payload->jobLogId;

        $partner = $payload->project->contributorByPromptId($contribution->partnerToken);

        if ($partner instanceof Expert) {
            $message->adjacencyPartner()->associate($partner);
        }

        $message->save();
        $payload->persisted($message);

        return $next($payload);
    }
}
