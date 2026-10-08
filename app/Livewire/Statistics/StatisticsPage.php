<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\ComparisonView;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Comparison;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: the user's own comparisons on the left, the open one on
 * the right. Every comparison id from the request is resolved through the
 * user's own comparisons — someone else's does not exist here (404, not 403).
 */
class StatisticsPage extends Component
{
    use NeedsConfirmation;

    #[Url(as: 'c')]
    public ?int $comparisonId = null;

    /** The comparison being renamed; null while the name modal creates a new one. */
    #[Locked]
    public ?int $renamingId = null;

    public string $name = '';

    public function mount(): void
    {
        if ($this->comparisonId !== null && $this->user()->comparisons()->find($this->comparisonId) === null) {
            $this->comparisonId = null;
        }
    }

    public function open(int $comparisonId): void
    {
        $this->comparisonId = $this->own($comparisonId)->id;
    }

    public function startCreating(): void
    {
        $this->reset(['renamingId', 'name']);
        $this->resetValidation();
        Flux::modal('comparison-name')->show();
    }

    public function startRenaming(int $comparisonId): void
    {
        $comparison = $this->own($comparisonId);
        $this->renamingId = $comparison->id;
        $this->name = $comparison->name;
        $this->resetValidation();
        Flux::modal('comparison-name')->show();
    }

    public function saveName(): void
    {
        $this->name = trim($this->name);
        $this->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => __('comparisons.name.field')]);

        if ($this->renamingId !== null) {
            $this->own($this->renamingId)->update(['name' => $this->name]);
        } else {
            $comparison = $this->user()->comparisons()->create([
                'name' => $this->name,
                'series' => [],
                'view' => (new ComparisonView)->toArray(),
            ]);
            $this->comparisonId = $comparison->id;
        }

        $this->reset(['renamingId', 'name']);
        Flux::modal('comparison-name')->close();
    }

    public function duplicate(int $comparisonId): void
    {
        $source = $this->own($comparisonId);
        $copy = $source->replicate();
        $copy->name = Str::limit(__('comparisons.copy_name', ['name' => $source->name]), 100, '');
        $copy->save();

        $this->comparisonId = $copy->id;
    }

    public function confirmDelete(int $comparisonId): void
    {
        $comparison = $this->own($comparisonId);

        $this->confirmTitle = __('comparisons.confirm.delete.title');
        $this->confirmMessage = __('comparisons.confirm.delete.message', ['name' => $comparison->name]);
        $this->needsConfirmation('delete', $comparisonId);
    }

    public function delete(int $comparisonId): void
    {
        $this->own($comparisonId)->delete();

        if ($this->comparisonId === $comparisonId) {
            $this->comparisonId = null;
        }
    }

    private function own(int $comparisonId): Comparison
    {
        return $this->user()->comparisons()->findOrFail($comparisonId);
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render(): mixed
    {
        return view('livewire.statistics.statistics-page', [
            'comparisons' => $this->user()->comparisons()->get(),
            'current' => $this->comparisonId === null ? null : $this->user()->comparisons()->find($this->comparisonId),
        ])->title(__('comparisons.heading'));
    }
}
