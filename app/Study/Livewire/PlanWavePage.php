<?php

namespace App\Study\Livewire;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Services\Transfer\UploadLimit;
use App\Study\Jobs\ApplyWave;
use App\Study\Models\StudyWave;
use App\Study\Planning\PreparedWave;
use App\Study\Planning\WavePlanning;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Upload a manifest, preview a wave exactly as study:plan --dry-run shows it,
 * then apply it: the wave row is recorded here and ApplyWave writes the runs in
 * the background, because thousands of runs outlast a web request. Admins only:
 * the route checks it for the first request, boot() for every update after it.
 */
class PlanWavePage extends Component
{
    use NeedsConfirmation, WithFileUploads;

    public ?TemporaryUploadedFile $manifest = null;

    public ?int $wave = 1;

    public ?int $from = 1;

    public ?int $to = null;

    public string $decision = '';

    /** PlanPreview::of() of the last preview; empty before one. */
    #[Locked]
    public array $overview = [];

    /** PreparedWave::fingerprint() of the last preview: apply() writes only what was shown. */
    #[Locked]
    public string $fingerprint = '';

    /** The wave being applied, kept in the URL so a reload still shows its progress. */
    #[Url]
    public ?int $applying = null;

    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function preview(): void
    {
        $prepared = $this->prepare();
        $this->overview = app(WavePlanning::class)->preview($prepared, auth()->user());
        $this->fingerprint = $prepared->fingerprint();
    }

    public function confirmApply(): void
    {
        if ($this->overview === [] || $this->overview['problems'] !== []) {
            return;
        }

        $this->confirmTitle = __('study::plan.confirm_title');
        $this->confirmMessage = __('study::plan.confirm_message', ['runs' => $this->overview['runs']['total']]);
        $this->confirmLabel = __('study::plan.apply');
        $this->needsConfirmation('apply');
    }

    /** Confirm target: prepares again from the uploaded file, and only what the preview showed is written. */
    public function apply(): void
    {
        $prepared = $this->prepare();

        if (! hash_equals($this->fingerprint, $prepared->fingerprint())) {
            $this->overview = [];
            $this->fingerprint = '';
            $this->addError('manifest', __('study::plan.changed'));

            return;
        }

        if (! $prepared->isAcceptable()) {
            $this->overview = app(WavePlanning::class)->preview($prepared, auth()->user(), withPrompts: false);

            return;
        }

        $wave = app(WavePlanning::class)->record($prepared, auth()->user());
        ApplyWave::dispatch($wave->id);
        $this->applying = $wave->id;
        $this->overview = [];
    }

    private function prepare(): PreparedWave
    {
        $this->validate([
            'manifest' => 'required|file|max:'.UploadLimit::kilobytes(),
            'wave' => 'required|integer|min:1',
            'from' => 'required|integer|min:1',
            'to' => 'required|integer|min:1',
            'decision' => 'nullable|string|max:5000',
        ]);

        return app(WavePlanning::class)->prepare($this->manifest->get(), $this->wave, $this->from, $this->to, $this->decision);
    }

    public function render(): mixed
    {
        $wave = $this->applying === null ? null : StudyWave::find($this->applying);

        return view('study::plan', [
            'applyingWave' => $wave,
            'written' => $wave?->writtenRuns(),
            'maxMegabytes' => UploadLimit::megabytes(),
        ])->title(__('study::plan.heading'));
    }
}
