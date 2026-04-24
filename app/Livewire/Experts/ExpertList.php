<?php

namespace App\Livewire\Experts;

use App\Models\Expert;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ExpertList extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public function hasActiveFilters(): bool
    {
        return trim($this->search) !== '';
    }

    #[On('expert_modified')]
    public function render(): mixed
    {
        $search = trim($this->search);

        $experts = Expert::query()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('job', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get();

        return view('livewire.experts.expert-list', [
            'experts' => $experts,
            'hasFilters' => $this->hasActiveFilters(),
        ]);
    }
}
