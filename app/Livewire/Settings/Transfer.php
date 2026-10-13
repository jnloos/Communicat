<?php

namespace App\Livewire\Settings;

use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\InvalidBundle;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Settings → Import/Export: export everything, or upload a bundle, see what
 * it would do, then import it and read what it did. Admins only, checked in
 * boot() so every request is covered, not only the first.
 */
class Transfer extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $bundle = null;

    #[Locked]
    public string $stage = 'upload';

    /** ImportReport::toArray() of the preview or the import. */
    #[Locked]
    public array $report = [];

    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function updatedBundle(): void
    {
        $this->validate(['bundle' => 'required|file|max:12288']);

        try {
            $this->report = app(BundleImporter::class)->preview($this->bundle->getRealPath(), auth()->user())->toArray();
            $this->stage = 'preview';
        } catch (InvalidBundle $exception) {
            $this->addError('bundle', $exception->getMessage());
        }
    }

    public function import(): void
    {
        if ($this->stage !== 'preview' || $this->bundle === null) {
            return;
        }

        $this->report = app(BundleImporter::class)->import($this->bundle->getRealPath(), auth()->user())->toArray();
        $this->stage = 'done';
        $this->bundle = null;
    }

    public function startOver(): void
    {
        $this->reset(['bundle', 'stage', 'report']);
    }

    public function render(): mixed
    {
        return view('livewire.settings.transfer', ['importReport' => ImportReport::fromArray($this->report)]);
    }
}
