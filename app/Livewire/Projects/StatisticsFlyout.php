<?php

namespace App\Livewire\Projects;

use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
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
 * It computes nothing itself: SpeakingShares owns the study's counting rules, so
 * the panel, the CLI and the thesis's evaluation script cannot disagree.
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

    public function render(): mixed
    {
        $report = $this->opened
            ? app(SpeakingShares::class)->forProject(Project::findOrFail($this->projectId))
            : ShareReport::empty();

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
        ]);
    }
}
