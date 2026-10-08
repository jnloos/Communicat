<?php

namespace App\Livewire\Tags;

use App\Models\TagCategory;
use App\Models\TagScope;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * One select per tag category of a scope, bound to the parent form through
 * wire:model. It never writes: the parent loads the selection with
 * tagSelection() and stores it in its own save() with syncTags(), so the tags
 * travel in the same request, and under the same authorization, as every
 * other field of the form.
 */
class TagFields extends Component
{
    #[Locked]
    public string $scope;

    /** @var array<int|string, int|string|null> category id => value id; '' means not set */
    #[Modelable]
    public array $selection = [];

    public function mount(string $scope): void
    {
        $this->scope = (TagScope::tryFrom($scope) ?? throw new InvalidArgumentException("Unknown tag scope [{$scope}]."))->value;
    }

    public function render(): mixed
    {
        return view('livewire.tags.tag-fields', [
            'categories' => TagCategory::ofScope(TagScope::from($this->scope))->with('values')->get(),
        ]);
    }
}
