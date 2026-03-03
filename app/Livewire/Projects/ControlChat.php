<?php
namespace App\Livewire\Projects;

use App\Jobs\Dependencies\ProjectJob;
use App\Jobs\MessageGenerator;
use App\Jobs\SummaryGenerator;
use App\Models\Project;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ControlChat extends Component
{
    #[Locked]
    public int $projectId;

    protected Project $project;

    public bool $keepGenerating = false;
    public bool $isSummarizing = false;
    public bool $isDispatching = false;

    #[Validate('required|string|min:3|max:1000')]
    public string $msgContent = '';


    public function mount(Project $project): void {
        $this->project = $project;
        $this->projectId = $project->id;
    }

    public function hydrate(): void {
        $this->project = Project::with('contributors')->findOrFail($this->projectId);
    }

    public function startGenerate(): void {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->isSummarizing = false;
        $this->keepGenerating = true;
        $this->isDispatching = true;

        MessageGenerator::dispatch($this->projectId);
    }

    public function stopGenerate(): void {
        $this->keepGenerating = false;
    }

    public function generateSummary(): void {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->keepGenerating = false;
        $this->isSummarizing = true;
        $this->isDispatching = true;

        SummaryGenerator::dispatch($this->projectId);
    }

    public function tick(): void {
        $jobRunning = ProjectJob::isRunningFor($this->projectId);

        // Job was successfully dispatched
        if ($jobRunning) {
            $this->isDispatching = false;
        }

        // Clear summarizing flag once execution finished
        if (! $jobRunning) {
            $this->isSummarizing = false;
        }

        // Schedule the next generator job if requested
        if ($this->keepGenerating && ! $jobRunning) {
            MessageGenerator::dispatch($this->projectId);
        }

        $this->dispatch('ticked');
    }

    public function sendMessage(): void {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->validate();
        $this->project->addMessage($this->msgContent, contributor: auth()->user());
        $this->dispatch('message_sent');
        $this->reset('msgContent');
    }

    #[On(['contributors_modified'])]
    public function render(): mixed {
        $jobRunning = ProjectJob::isRunningFor($this->projectId);

        return view('livewire.projects.control-chat', [
            'shouldPoll' => $this->keepGenerating || $this->isSummarizing || $jobRunning,
            'disableAll' => $jobRunning || $this->isDispatching,
            'showGenerate' => ! $this->keepGenerating,
            'showStop' => $this->keepGenerating,
            'isSummarizing' => $this->isSummarizing,
        ]);
    }
}
