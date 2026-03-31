<?php

namespace App\Livewire\Projects;

use App\Events\GenerationStarted;
use App\Events\GenerationStopped;
use App\Events\MessageSent;
use App\Jobs\Dependencies\ProjectJob;
use App\Jobs\MessageGenerator;
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
    public bool $isDispatching  = false;

    #[Validate('required|string|min:3|max:1000')]
    public string $msgContent = '';

    public function mount(Project $project): void {
        $this->project   = $project;
        $this->projectId = $project->id;
    }

    public function hydrate(): void {
        $this->project = Project::findOrFail($this->projectId);
    }

    public function startGenerate(): void {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->keepGenerating = true;
        $this->isDispatching  = true;

        GenerationStarted::dispatch($this->projectId);
        MessageGenerator::dispatch($this->projectId);
    }

    public function stopGenerate(): void {
        GenerationStopped::dispatch($this->projectId);
    }

    #[On('echo-private:projects.{projectId},.GenerationStarted')]
    public function onGenerationStarted(): void {
        $this->isDispatching = true;
    }

    #[On('echo-private:projects.{projectId},.GenerationStopped')]
    public function onGenerationStopped(): void {
        $this->keepGenerating = false;
        $this->isDispatching  = false;
    }

    #[On('echo-private:projects.{projectId},.MessageGenerated')]
    public function onMessageGenerated(): void {
        $this->isDispatching = false;

        $this->dispatch('message_generated');

        if ($this->keepGenerating) {
            $this->isDispatching = true;
            MessageGenerator::dispatch($this->projectId);
        }
    }

    public function sendMessage(): void {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->validate();
        $this->project->addMessage($this->msgContent, auth()->user());
        MessageSent::dispatch($this->projectId);
        $this->dispatch('message_sent');
        $this->reset('msgContent');
    }

    #[On(['contributors_modified'])]
    public function render(): mixed {
        $jobRunning = ProjectJob::isRunningFor($this->projectId);

        return view('livewire.projects.control-chat', [
            'disableInput'    => $jobRunning || $this->isDispatching,
            'disableGenerate' => $jobRunning || $this->isDispatching,
            'showGenerate'    => !$this->keepGenerating && !$this->isDispatching,
        ]);
    }
}
