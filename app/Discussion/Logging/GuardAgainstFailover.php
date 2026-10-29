<?php

namespace App\Discussion\Logging;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\ProviderFailedOver;

/**
 * The SDK can silently switch providers when a call fails. For this study that
 * would break a run: the model family per run must stay homogeneous, and a
 * silent switch would not show up anywhere in the measurement. Nothing here
 * configures failover -- this only makes noise if the SDK reports one.
 *
 * The SDK fires the event after every failoverable error, also when exactly one
 * provider was passed (every call of this study) and there is nothing to switch
 * to: then it is a transient error of that one provider, not a change of model
 * family. The message says what failed so the two can be told apart; a call with
 * a second provider configured is the case to worry about.
 */
class GuardAgainstFailover
{
    public function handle(object $event): void
    {
        $what = $event instanceof ProviderFailedOver
            ? sprintf(' %s [%s] failed with %s.', $event->provider->name(), $event->model, $event->exception::class)
            : '';

        Log::error('An AI provider failover event fired.'.$what.' With one provider this is only a transient error and the run stays in its model family; if a second provider was configured, the run may no longer be homogeneous. Event: '.$event::class);
    }
}
