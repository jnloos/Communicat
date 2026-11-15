<?php

namespace App\Study\Progress;

use App\Study\Control\PauseSource;
use App\Study\Control\PauseState;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * The compact text of a snapshot for /status, the report and the mail. Counts, pace and
 * failure classes only (never the error text): see StudyBlindnessTest.
 */
class StatusText
{
    private const MAX_LENGTH = 1800;

    public function summary(StudySnapshot $snapshot): string
    {
        if (! $snapshot->hasCells()) {
            return __('study::watch.status.no_cells', ['categories' => implode(', ', $snapshot->progress['missing'])]);
        }

        $progress = $snapshot->progress;
        $totals = $progress['totals'];

        $lines = [
            '**'.($snapshot->pause === null ? __('study::watch.status.running') : __('study::watch.status.paused', ['since' => $snapshot->pause->since->format('H:i'), 'reason' => $snapshot->pause->reason])).'** · '
                .($snapshot->wave === null ? __('study::progress.all_waves') : __('study::progress.wave').' '.($progress['waves'][$snapshot->wave] ?? $snapshot->wave)),
            __('study::watch.status.done', ['done' => $totals['done'], 'total' => $totals['total'], 'started' => $totals['started'], 'open' => $totals['open'], 'given_up' => $totals['given_up']]),
            __('study::watch.status.pace', [
                'turns' => $progress['turnsPerHour'],
                'hours' => $progress['hoursLeft'] === null ? '–' : number_format($progress['hoursLeft'], 1),
                'last' => $this->ago($progress['lastTurnAt'] === null ? null : Carbon::parse($progress['lastTurnAt'])),
                'worker' => $this->ago($snapshot->workerSeenAt),
                'queued' => $progress['queued'],
            ]),
            ...$this->pipelines($progress['grids']),
            ...$this->disruptions($progress['disruptions']),
            ...$this->givenUp($progress['failedJobs']),
        ];

        return Str::limit(implode("\n", $lines), self::MAX_LENGTH, '…');
    }

    public function source(PauseState $state): string
    {
        return match ($state->source) {
            PauseSource::Breaker => __('study::watch.source.breaker'),
            PauseSource::Manual => __('study::watch.source.manual'),
            null => __('study::watch.source.unknown'),
        };
    }

    /** @return list<string> one "Pipeline: done/total" line each */
    private function pipelines(array $grids): array
    {
        return array_map(function (string $pipeline) use ($grids) {
            $cells = collect($grids[$pipeline])->flatten(1);

            return "{$pipeline}: {$cells->sum('done')}/{$cells->sum('total')}";
        }, array_keys($grids));
    }

    /** @return list<string> the three most frequent classes of the last hour */
    private function disruptions(array $disruptions): array
    {
        $top = array_slice($disruptions, 0, 3, true);

        return $top === [] ? [] : [__('study::watch.status.disruptions').': '.collect($top)->map(fn ($count, $kind) => "{$kind} ×{$count}")->implode(', ')];
    }

    /** @return list<string> the last three: project id and error class, never the message */
    private function givenUp(array $failedJobs): array
    {
        $last = array_slice($failedJobs, 0, 3);

        return $last === [] ? [] : [__('study::watch.status.given_up').': '.collect($last)->map(fn ($job) => '#'.($job['project'] ?? '?').' '.Str::before((string) $job['reason'], ':'))->implode(', ')];
    }

    private function ago(?CarbonInterface $at): string
    {
        return $at === null ? '–' : $at->diffForHumans();
    }
}
