<?php

namespace App\Livewire\Experts;

use App\Models\Expert;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Read-only persona viewer: shows an expert's profile, core beliefs,
 * knowledge limits, style and tags without exposing the editor. Opened
 * via the `open-expert-details` event from expert cards.
 */
class ExpertDetailsFlyout extends Component
{
    public ?int $expertId = null;

    #[On('open-expert-details')]
    public function openFor(int $expertId): void
    {
        $this->expertId = $expertId;
        Flux::modal('expert-details-flyout')->show();
    }

    public function render(): mixed
    {
        $expert = $this->expertId ? Expert::with('tags')->find($this->expertId) : null;

        return view('livewire.experts.expert-details-flyout', [
            'expert' => $expert,
        ]);
    }
}
