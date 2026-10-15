<?php

namespace App\Livewire\Projects;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\CurveMode;
use App\Discussion\Values\ShareChartData;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\ShareUnit;
use App\Discussion\Values\Trajectory;
use App\Models\Project;
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

    #[Locked]
    public int $projectId;

    /** Computed on first open, not on mount: an unopened flyout should cost no queries. */
    public bool $opened = false;

    /** Which unit the line chart shows. */
    public ShareUnit $trajectoryUnit = ShareUnit::Turns;

    /** How the line chart aggregates. */
    public CurveMode $trajectoryMode = CurveMode::Cumulative;

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

    public function render(): mixed
    {
        $project = $this->opened ? Project::findOrFail($this->projectId) : null;

        $report = $project
            ? app(SpeakingShares::class)->forProject($project)
            : ShareReport::empty();

        $trajectory = $project
            ? app(ShareTrajectory::class)->forProject($project)
            : new Trajectory(participants: [], window: (int) config('discussion.share_window'), points: []);

        $charts = new ShareChartData($report, $trajectory, ShareSeries::of($this->trajectoryMode, $this->trajectoryUnit));

        return view('livewire.projects.statistics-flyout', [
            'report' => $report,
            'charts' => $charts,
            'trajectoryWindow' => $trajectory->window,
            'trajectoryRows' => $charts->trajectoryRows(),
            'trajectoryLines' => $charts->trajectoryLines(),
        ]);
    }
}
