<?php

namespace App\Discussion\Stages;

use App\Discussion\TurnPayload;
use App\Models\Expert;
use Closure;

/** Saves the public contribution. Needs SelectSpeaker and Speak before it. */
class PersistMessage
{
    public function handle(TurnPayload $payload, Closure $next)
    {
        $contribution = $payload->contribution();

        $message = $payload->project->addMessage($contribution->text, $payload->selection()->speaker);
        $message->job_log_id = $payload->jobLogId;

        $addressee = $payload->project->contributorByPromptId($contribution->addresseeToken);

        if ($addressee instanceof Expert) {
            $message->addressee()->associate($addressee);
        }

        $message->save();
        $payload->persisted($message);

        return $next($payload);
    }
}
