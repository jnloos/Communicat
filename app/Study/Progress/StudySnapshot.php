<?php

namespace App\Study\Progress;

use App\Study\Control\PauseFlag;
use App\Study\Control\PauseState;
use App\Study\Models\StudyEvent;
use App\Study\Watch\WorkerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Everything the dashboard, the watcher and /status show, read once: StudyProgress's report
 * plus pause, worker signal and latest events. The one source for all three, so none of them
 * builds its own standstill rule. Operations only, like StudyProgress: no share, no effect.
 */
final readonly class StudySnapshot
{
    private const EVENTS_SHOWN = 10;

    /** @param Collection<int, StudyEvent> $recentEvents */
    public function __construct(
        public array $progress,
        public ?PauseState $pause,
        public ?CarbonImmutable $workerSeenAt,
        public Collection $recentEvents,
        public ?int $wave = null,
    ) {}

    public static function read(?int $wave = null): self
    {
        $seen = Cache::get(WorkerHeartbeat::CACHE_KEY);

        return new self(
            app(StudyProgress::class)->read($wave),
            app(PauseFlag::class)->state(),
            $seen === null ? null : CarbonImmutable::createFromTimestamp($seen),
            StudyEvent::orderByDesc('id')->limit(self::EVENTS_SHOWN)->get(),
            $wave,
        );
    }

    public function hasCells(): bool
    {
        return $this->progress['missing'] === [];
    }

    public function remaining(): int
    {
        return $this->progress['remaining'] ?? 0;
    }

    /** StudyProgress's rule, not a second one. */
    public function standstill(): bool
    {
        return $this->progress['stale'] ?? false;
    }

    public function finished(): bool
    {
        $totals = $this->progress['totals'] ?? ['total' => 0, 'done' => 0];

        return $totals['total'] > 0 && $totals['done'] === $totals['total'];
    }

    /** No signal from a study worker for as long as a turn may stay missing, while runs are open. */
    public function workerSilent(): bool
    {
        return $this->remaining() > 0
            && ($this->workerSeenAt === null || $this->workerSeenAt->diffInMinutes(now(), true) > config('study.stale_after_minutes'));
    }
}
