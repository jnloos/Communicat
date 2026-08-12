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

    public bool $isDispatching = false;

    public bool $userInputRequested = false;

    /** Durable per-project toggle: continue the discussion after a user message. */
    public bool $autoplay = false;

    /** Transient hint shown above the composer when an action is blocked. */
    public ?string $controlWarning = null;

    #[Validate('required|string|min:3|max:1000')]
    public string $msgContent = '';

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->projectId = $project->id;
    }

    public function hydrate(): void
    {
        $this->project = Project::findOrFail($this->projectId);
    }

    /**
     * Read-only guard for the shared onboarding demo: block any mutating action
     * (send / generate / autoplay) and surface a hint. The buttons stay visible
     * so the welcome tour can still explain them.
     */
    protected function demoBlocked(): bool
    {
        if ($this->project->isDemo()) {
            $this->controlWarning = __('Die Demo-Diskussion ist schreibgeschützt.');

            return true;
        }

        return false;
    }

    /**
     * Enough-experts guard: a discussion may only start/continue with at least
     * MIN_CONTRIBUTING_EXPERTS. Surfaces a hint instead of dispatching a job.
     */
    protected function tooFewExperts(): bool
    {
        if (! $this->project->canStartDiscussion()) {
            $this->controlWarning = __('Füge mindestens :n Experten hinzu, um die Diskussion zu starten.', ['n' => Project::MIN_CONTRIBUTING_EXPERTS]);

            return true;
        }

        return false;
    }

    public function startGenerate(): void
    {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->controlWarning = null;

        if ($this->demoBlocked() || $this->tooFewExperts()) {
            return;
        }

        // Raise the shared flag FIRST so the self-perpetuating job loop knows to
        // keep going; the per-component flags below are only for this browser's
        // button state and are kept in sync via broadcasts.
        ProjectJob::startGenerating($this->projectId);
        // Seed presence so the very first continuation check sees this viewer
        // even before the heartbeat poll has fired.
        ProjectJob::markViewing($this->projectId);

        $this->keepGenerating = true;
        $this->isDispatching = true;
        $this->userInputRequested = false;

        GenerationStarted::dispatch($this->projectId);
        MessageGenerator::dispatch($this->projectId);
    }

    public function stopGenerate(): void
    {
        // Authoritative, shared stop: clearing the flag halts the loop after the
        // current turn for every connected user. Broadcast flips all clients'
        // buttons back to "start".
        ProjectJob::stopGenerating($this->projectId);
        $this->keepGenerating = false;
        $this->isDispatching = false;

        GenerationStopped::dispatch($this->projectId);
    }

    #[On('echo-private:projects.{projectId},.GenerationStarted')]
    public function onGenerationStarted(): void
    {
        // Sync EVERY connected user (not just the one who pressed start) to the
        // generating state, so their button shows "pause" and they can stop too.
        $this->keepGenerating = true;
        $this->isDispatching = true;
        $this->userInputRequested = false;
    }

    #[On('echo-private:projects.{projectId},.GenerationStopped')]
    public function onGenerationStopped(): void
    {
        $this->keepGenerating = false;
        $this->isDispatching = false;
    }

    #[On('echo-private:projects.{projectId},.MessageGenerated')]
    public function onMessageGenerated(): void
    {
        // The loop is now driven server-side: MessageGenerator re-dispatches
        // itself while the shared flag is set, so this listener only reflects
        // button state. `isDispatching` mirrors whether the loop is still live.
        $this->isDispatching = $this->keepGenerating;

        // The browser-side `message_generated` event is dispatched by
        // ProjectChat after IT re-rendered (so voice-stage's data-*
        // attributes are fresh). Duplicating the dispatch here would race
        // ahead of the DOM morph.
    }

    #[On('echo-private:projects.{projectId},.UserInputRequested')]
    public function onUserInputRequested(array $event = []): void
    {
        // Generation has halted for everyone.
        $this->keepGenerating = false;
        $this->isDispatching = false;

        // Only the addressed user gets the "your input is requested" prompt.
        // MessageGenerator resolves the target (pending message author, else the
        // project owner) before dispatching, so it is never null in practice.
        $targetUserId = $event['targetUserId'] ?? null;
        if ($targetUserId !== null && $targetUserId !== auth()->id()) {
            return;
        }

        $this->userInputRequested = true;
        $this->dispatch('user-input-requested', projectId: $this->projectId);
    }

    public function sendMessage(): void
    {
        if (ProjectJob::isRunningFor($this->projectId)) {
            return;
        }

        $this->controlWarning = null;

        if ($this->demoBlocked()) {
            return;
        }

        $this->validate();
        $this->project->addMessage($this->msgContent, auth()->user());
        MessageSent::dispatch($this->projectId, auth()->id());
        $this->dispatch('message_sent');
        $this->dispatch('user-input-cleared', projectId: $this->projectId);
        $this->reset('msgContent');
        $this->userInputRequested = false;

        // Autoplay: when enabled, the discussion continues on its own after a
        // user message (mirrors startGenerate) instead of waiting for a click.
        // Still gated by the minimum-experts rule.
        if (($this->project->settings['autoplay'] ?? false)
            && $this->project->canStartDiscussion()
            && ! ProjectJob::isGenerating($this->projectId)) {
            ProjectJob::startGenerating($this->projectId);
            ProjectJob::markViewing($this->projectId);
            $this->keepGenerating = true;
            $this->isDispatching = true;
            GenerationStarted::dispatch($this->projectId);
            MessageGenerator::dispatch($this->projectId);
        }
    }

    /**
     * Flip the durable per-project autoplay preference. Persisted in settings so
     * it survives reloads and is shared across everyone viewing the project.
     */
    public function toggleAutoplay(): void
    {
        if ($this->demoBlocked()) {
            return;
        }

        $settings = $this->project->settings ?? [];
        $settings['autoplay'] = ! ($settings['autoplay'] ?? false);
        $this->project->settings = $settings;
        $this->project->save();

        $this->autoplay = $settings['autoplay'];
    }

    /**
     * Viewer-presence heartbeat. Polled by open discussions while generating so
     * the server loop knows at least one user still has the chat open.
     */
    public function heartbeat(): void
    {
        ProjectJob::markViewing($this->projectId);
    }

    public function updatedMsgContent(string $value): void
    {
        if ($this->userInputRequested && trim($value) !== '') {
            $this->userInputRequested = false;
            $this->dispatch('user-input-cleared', projectId: $this->projectId);
        }
    }

    #[On(['contributors_modified'])]
    public function render(): mixed
    {
        // The shared cache flag is the source of truth for "is the discussion
        // generating", so even a user who opened the page mid-run shows the
        // correct (pause) button and can stop it.
        $this->keepGenerating = ProjectJob::isGenerating($this->projectId);
        $this->autoplay = (bool) ($this->project->settings['autoplay'] ?? false);

        $jobRunning = ProjectJob::isRunningFor($this->projectId);

        $disabledControlsHint = null;
        if ($jobRunning) {
            $disabledControlsHint = __('Another operation is in progress. Try again in a moment.');
        } elseif ($this->isDispatching) {
            $disabledControlsHint = __('Waiting for the current expert message…');
        }

        $mentionables = $this->project->contributingExperts()
            ->map(fn ($expert) => [
                'name' => $expert->name,
                'job' => $expert->job,
                'avatar_url' => $expert->avatar_url,
            ])
            ->values()
            ->all();

        return view('livewire.projects.control-chat', [
            'disableInput' => $jobRunning || $this->isDispatching,
            // The START button is disabled while a turn is mid-flight; the STOP
            // button must ALWAYS be clickable (that was the "can't stop" bug).
            'disableGenerate' => $jobRunning || $this->isDispatching,
            'disableStop' => false,
            'showGenerate' => ! $this->keepGenerating,
            'disabledControlsHint' => $disabledControlsHint,
            'userInputRequested' => $this->userInputRequested,
            // Hard minimum-experts gate: below MIN_CONTRIBUTING_EXPERTS the start
            // button is disabled and explains why.
            'canStart' => $this->project->canStartDiscussion(),
            'controlWarning' => $this->controlWarning,
            'autoplay' => $this->autoplay,
            'mentionables' => $mentionables,
        ]);
    }
}
