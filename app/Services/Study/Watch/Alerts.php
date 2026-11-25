<?php

namespace App\Services\Study\Watch;

use App\Services\Study\Control\PauseSource;
use App\Services\Study\Control\PauseState;
use App\Services\Study\Progress\StatusText;
use App\Services\Study\Progress\StudySnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** The texts of the alerts, in one place: the watcher and the halt listener must say the same. */
class Alerts
{
    public function __construct(private readonly StatusText $statusText) {}

    /** Fresh signal and an old turn means the jobs run and fail; an old signal means the workers are dead. */
    public function standstill(StudySnapshot $snapshot): Alert
    {
        $lastTurn = $snapshot->progress['lastTurnAt'] ?? null;
        $signalFresh = $snapshot->workerSeenAt !== null && ! $snapshot->workerSilent();

        return new Alert('standstill', AlertLevel::Crit,
            __('study.watch.standstill.title', ['ago' => $lastTurn === null ? __('study.watch.never') : Carbon::parse($lastTurn)->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE)]),
            __('study.watch.standstill.signal', ['ago' => $this->ago($snapshot->workerSeenAt)]).' '
                .__($signalFresh ? 'study.watch.standstill.alive' : 'study.watch.standstill.dead'));
    }

    public function paused(PauseState $state): Alert
    {
        return new Alert('paused', $state->source === PauseSource::Manual ? AlertLevel::Info : AlertLevel::Crit,
            __('study.watch.paused.title', ['source' => $this->statusText->source($state)]),
            __('study.watch.paused.body', ['since' => $state->since->format('H:i'), 'reason' => $state->reason, 'signal' => $state->signal]));
    }

    public function waveFinished(StudySnapshot $snapshot): Alert
    {
        return new Alert('wave_finished', AlertLevel::Info, __('study.watch.wave_finished.title'),
            __('study.watch.wave_finished.body', ['total' => $snapshot->progress['totals']['total']]));
    }

    public function report(StudySnapshot $snapshot): Alert
    {
        return new Alert(Notifier::REPORT, AlertLevel::Info, __('study.watch.report'), $this->statusText->summary($snapshot));
    }

    private function ago(?CarbonInterface $at): string
    {
        return $at === null ? __('study.watch.never') : $at->diffForHumans();
    }
}
