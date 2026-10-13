<?php

namespace App\Livewire\Settings;

use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\InvalidBundle;
use App\Services\Transfer\UploadLimit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

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
        $this->stage = 'upload';
        $this->report = [];

        try {
            $this->validate(['bundle' => 'required|file|max:'.UploadLimit::kilobytes()]);

            $this->report = app(BundleImporter::class)->preview($this->bundle->getRealPath(), auth()->user())->toArray();
            $this->stage = 'preview';
        } catch (ValidationException $exception) {
            $this->discardBundle();

            throw $exception;
        } catch (InvalidBundle $exception) {
            $this->rejectBundle($exception->getMessage());
        } catch (Throwable $exception) {
            $this->rejectBundle(__('transfer.import.failed', ['message' => $exception->getMessage()]));
        }
    }

    public function import(): void
    {
        if ($this->stage !== 'preview' || $this->bundle === null) {
            return;
        }

        $this->report = app(BundleImporter::class)->import($this->bundle->getRealPath(), auth()->user())->toArray();
        $this->stage = 'done';
        $this->discardBundle();
    }

    public function startOver(): void
    {
        $this->discardBundle();
        $this->reset(['stage', 'report']);
    }

    /** The upload may hold prompt texts: delete the temporary file as soon as it has served. */
    private function discardBundle(): void
    {
        $this->bundle?->delete();
        $this->bundle = null;
    }

    private function rejectBundle(string $message): void
    {
        $this->discardBundle();
        $this->addError('bundle', $message);
    }

    public function render(): mixed
    {
        return view('livewire.settings.transfer', [
            'importReport' => ImportReport::fromArray($this->report),
            'maxMegabytes' => UploadLimit::megabytes(),
        ])->title(__('transfer.heading'));
    }
}
