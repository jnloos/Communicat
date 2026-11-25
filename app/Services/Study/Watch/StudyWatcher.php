<?php

namespace App\Services\Study\Watch;

use App\Services\Study\Progress\StudySnapshot;
use Illuminate\Support\Facades\Cache;

/**
 * The checks of one minute over the whole study (every wave). Each condition goes through the
 * AlertBook, which decides whether it is news; the report has its own clock.
 */
class StudyWatcher
{
    private const REPORT_KEY = 'study.report.last';

    private const PAUSE_SEEN_KEY = 'study.pause.seen';

    public function __construct(private readonly AlertBook $book, private readonly Alerts $alerts, private readonly Notifier $notifier) {}

    public function run(): void
    {
        $snapshot = StudySnapshot::read();

        $this->syncStandstill($snapshot);
        $this->book->sync('paused', $snapshot->pause !== null, fn () => $this->alerts->paused($snapshot->pause));
        $this->book->sync('wave_finished', $snapshot->finished(), fn () => $this->alerts->waveFinished($snapshot), once: true);

        $this->reportWhenDue($snapshot);
    }

    /**
     * A pause is the cause of the standstill and has its own alert, so the standstill is not
     * judged while it lasts (neither raised nor cleared) nor for stale_after_minutes after it
     * was last seen: the last turn is older than the pause, which says nothing about the workers.
     */
    private function syncStandstill(StudySnapshot $snapshot): void
    {
        if ($snapshot->pause !== null) {
            Cache::forever(self::PAUSE_SEEN_KEY, now()->timestamp);

            return;
        }

        $pausedAt = Cache::get(self::PAUSE_SEEN_KEY);

        if ($pausedAt === null || now()->timestamp - $pausedAt >= config('study.stale_after_minutes') * 60) {
            $this->book->sync('standstill', $snapshot->standstill(), fn () => $this->alerts->standstill($snapshot));
        }
    }

    private function reportWhenDue(StudySnapshot $snapshot): void
    {
        $hours = config('study.watch.report_every_hours');

        if ($hours <= 0 || ! ($snapshot->remaining() > 0 || $snapshot->pause !== null)) {
            return;
        }

        $last = Cache::get(self::REPORT_KEY);

        // A lost cache means an early report, which is harmless.
        if ($last !== null && now()->timestamp - $last < $hours * 3600) {
            return;
        }

        Cache::forever(self::REPORT_KEY, now()->timestamp);
        $this->notifier->send($this->alerts->report($snapshot), mail: false);
    }
}
