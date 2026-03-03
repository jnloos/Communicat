<?php

namespace App\Livewire\Experts;

use App\Models\Expert;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class ExpertList extends Component
{
    #[On('expert_modified')]
    public function render(): mixed {
        $experts = Expert::orderBy('name')->get();
        return view('livewire.experts.expert-list', [
            'experts' => $experts,
        ]);
    }
}
