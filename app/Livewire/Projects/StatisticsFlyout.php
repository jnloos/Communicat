<?php

namespace App\Livewire\Projects;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\Participant;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Models\Project;
use Closure;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The two measures of the study, for the discussion in front of you: how often
 * each agent got the floor, and how much of the text each wrote.
 *
 * Both pies read the same report, and a participant keeps the same colour in
 * both — that is the whole point of putting them side by side. Where a slice is
 * larger on the right than on the left, that agent speaks rarely and at length.
 *
 * Below them, a line per participant shows how those shares developed turn by
 * turn (ShareTrajectory), in the same colours — cumulative, to see where the
 * run settles, or over a sliding window, to see whether it is still moving.
 *
 * It computes nothing itself: SpeakingShares and ShareTrajectory read the
 * study's counting rules from SpokenTurns, so the panel, the CLI and the
 * thesis's evaluation script cannot disagree.
 */
class StatisticsFlyout extends Component
{
    public const MODAL = 'statistics-flyout';

    /** The palette Flux allows for pie sectors, in the order seats are handed out. */
    private const COLOURS = ['blue', 'violet', 'emerald', 'amber', 'rose', 'cyan', 'fuchsia', 'lime'];

    #[Locked]
    public int $projectId;

    /** Computed on first open, not on mount: an unopened flyout should cost no queries. */
    public bool $opened = false;

    /** Which unit the line chart shows: `turns` or `words`. */
    public string $trajectoryUnit = 'turns';

    /** How the line chart aggregates: `cumulative` or `rolling`. */
    public string $trajectoryMode = 'cumulative';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[On('open-statistics')]
    public function open(): void
    {
        $this->opened = true;
        Flux::modal(self::MODAL)->show();
    }

    #[On('echo-private:projects.{projectId},.MessageGenerated')]
    public function onMessageGenerated(): void
    {
        // A closed panel has nothing to redraw, and every turn broadcasts here.
        if (! $this->opened) {
            $this->skipRender();
        }
    }

    /**
     * One row per participant for a Flux pie: the value, the label, and the
     * colour. The colour comes from the seat, not from the row order, so a
     * participant looks the same in both charts and in the table.
     *
     * The key must be spelled `color`: Flux reads `datum.color` and paints
     * `var(--color-<name>-500)`, and an unknown name silently falls back to a
     * hash of the label — which would give the same participant two different
     * colours in the two charts.
     *
     * `share` is pre-formatted here rather than in the chart: the tooltip prints
     * whatever the datum holds, and the locale's decimal comma is a server-side
     * concern.
     *
     * @param  Closure(SpeakerShare): int  $amount  the unit's absolute value
     * @param  Closure(SpeakerShare): float  $fraction  the same unit as a share, 0..1
     * @return list<array{name: string, value: int, share: string, color: string}>
     */
    private function sectors(ShareReport $report, Closure $amount, Closure $fraction): array
    {
        $rows = [];

        foreach ($report->speakers as $index => $share) {
            $value = $amount($share);

            // Flux drops a zero sector anyway; leaving it out keeps the payload
            // honest. A participant who never spoke stays visible in the table.
            if ($value > 0) {
                $rows[] = [
                    'name' => $share->name,
                    'value' => $value,
                    'share' => number_format($fraction($share) * 100, 1, ',', '.').' %',
                    'color' => $this->colorFor($index),
                ];
            }
        }

        return $rows;
    }

    /** Colour by seat, not by row order, so it holds across both charts and the table. */
    public function colorFor(int $index): string
    {
        return self::COLOURS[$index % count(self::COLOURS)];
    }

    /**
     * The curve the toggles ask for. Both toggles are public and the browser
     * can send anything, so an unknown combination falls back to the default
     * instead of breaking the panel.
     */
    private function series(): ShareSeries
    {
        return ShareSeries::tryFrom("{$this->trajectoryMode}_{$this->trajectoryUnit}") ?? ShareSeries::CumulativeTurns;
    }

    /**
     * One row per spoken turn for a Flux line chart: the turn on the x axis, its
     * tooltip heading, and one percentage per participant under the key
     * `e{expert id}` that the participant's line reads.
     *
     * @return list<array<string, int|float|string>>
     */
    private function trajectoryRows(Trajectory $trajectory, ShareSeries $series): array
    {
        $rows = [];

        foreach ($trajectory->points as $point) {
            $row = ['turn' => $point->turn, 'label' => __('statistics.trajectory.turn', ['turn' => $point->turn])];

            foreach ($trajectory->participants as $participant) {
                $row[$this->fieldFor($participant)] = round($point->share($series, $participant->expertId) * 100, 1);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One line per participant, including one who never spoke: unlike a pie
     * sector, a line flat at zero says something. Coloured by seat index, the
     * same index the pies and the table use.
     *
     * @return list<array{field: string, name: string, color: string}>
     */
    private function trajectoryLines(Trajectory $trajectory): array
    {
        $lines = [];

        foreach ($trajectory->participants as $index => $participant) {
            $lines[] = [
                'field' => $this->fieldFor($participant),
                'name' => $participant->name,
                'color' => $this->colorFor($index),
            ];
        }

        return $lines;
    }

    private function fieldFor(Participant $participant): string
    {
        return 'e'.$participant->expertId;
    }

    public function render(): mixed
    {
        $project = $this->opened ? Project::findOrFail($this->projectId) : null;

        $report = $project
            ? app(SpeakingShares::class)->forProject($project)
            : ShareReport::empty();

        $trajectory = $project
            ? app(ShareTrajectory::class)->forProject($project)
            : new Trajectory(participants: [], window: (int) config('discussion.share_window'), points: []);

        return view('livewire.projects.statistics-flyout', [
            'report' => $report,
            'turnSectors' => $this->sectors(
                $report,
                fn (SpeakerShare $s) => $s->turns,
                fn (SpeakerShare $s) => $s->turnShare,
            ),
            'wordSectors' => $this->sectors(
                $report,
                fn (SpeakerShare $s) => $s->words,
                fn (SpeakerShare $s) => $s->wordShare,
            ),
            'trajectoryWindow' => $trajectory->window,
            'trajectoryRows' => $this->trajectoryRows($trajectory, $this->series()),
            'trajectoryLines' => $this->trajectoryLines($trajectory),
        ]);
    }
}
