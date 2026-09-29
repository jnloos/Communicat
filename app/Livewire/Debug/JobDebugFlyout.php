<?php

namespace App\Livewire\Debug;

use App\Livewire\Debug\Concerns\ShowsJobReport;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The job report for one discussion, opened from the chat's control bar.
 *
 * The same report also has a standalone page (JobDebugPanel). The difference is
 * scope, not content: the page lets you pick a project, this one is pinned to the
 * discussion you are watching, so the report belongs to the run in front of you
 * instead of being a place you navigate away to.
 */
class JobDebugFlyout extends Component
{
    use ShowsJobReport;

    public const MODAL = 'job-debug-flyout';

    #[Locked]
    public int $projectId;

    /** Loaded on first open, not on mount: an unopened flyout should cost no queries. */
    public bool $opened = false;

    public function mount(Project $project): void
    {
        // The two views that render this component check the same flag, but the
        // guard belongs here as well: a third view that forgets it would expose
        // the report in production without anything failing.
        abort_unless(config('app.debug'), 404);

        $this->projectId = $project->id;
    }

    #[On('open-job-debug')]
    public function open(): void
    {
        $this->opened = true;
        Flux::modal(self::MODAL)->show();
    }

    /**
     * A closed flyout has nothing to show, so a live update need not reach it.
     *
     * Being open is the whole condition here: unlike the standalone page, this
     * flyout has no pause control, so there is no second way for it to fall
     * behind the run it is pinned to.
     */
    protected function jobReportIsFrozen(): bool
    {
        return ! $this->opened;
    }

    public function render(): mixed
    {
        return view(
            'livewire.debug.job-debug-flyout',
            $this->jobReportFor($this->opened ? $this->projectId : null),
        );
    }
}
