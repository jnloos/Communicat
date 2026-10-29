<?php

namespace App\Discussion\Jev;

use App\Discussion\TurnPayload;
use App\Discussion\Values\SelectionRecord;
use App\Models\JobLog;
use Illuminate\Support\Facades\Log;

/**
 * Ends a run whose decision maker has stopped answering. One turn in which Jev
 * fell back is a hiccup the run absorbs (the floor goes to a reproducible draw
 * and is flagged); a run of them is an outage, and carrying on would fill the
 * cell with random speakers that look like data.
 *
 * After `ai.jev.max_fallback_turns` turns in a row in which EVERY Jev request
 * fell back (this turn included) the turn is halted with the existing mechanism,
 * TurnPayload::halt(), before anybody speaks, so at most one fewer than that
 * number of random-speaker turns reach the data. The turn is still logged, with
 * its selection, so a restarted run sees the outage and halts again at once while
 * Jev is still down. A turn in which Jev was not asked, or answered, or in which
 * the previous turn failed outright, ends the streak. 0 switches the guard off.
 */
class JevOutageGuard
{
    public const REASON = 'jev_outage';

    public function haltIfDown(TurnPayload $payload, bool $everyJevCallFellBack): void
    {
        $limit = (int) config('ai.jev.max_fallback_turns', 3);

        if ($limit < 1 || ! $everyJevCallFellBack || ! $this->precededByFallbackTurns($payload, $limit - 1)) {
            return;
        }

        Log::error('Jev has not given a usable answer for {turns} turns in a row; the run is stopped.', ['turns' => $limit, 'project_id' => $payload->project->id, 'turn' => $payload->turnIndex]);

        $payload->halt(self::REASON);
    }

    private function precededByFallbackTurns(TurnPayload $payload, int $count): bool
    {
        if ($count === 0) {
            return true;
        }

        $previous = JobLog::where('project_id', $payload->project->id)
            ->where('turn_index', '<', $payload->turnIndex)
            ->orderByDesc('turn_index')
            ->limit($count)
            ->get(['selection']);

        return $previous->count() === $count
            && $previous->every(fn (JobLog $log) => SelectionRecord::fromArray($log->selection)->everyJevCallFellBack());
    }
}
