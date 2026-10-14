<?php

namespace App\Livewire\Experts;

use App\Discussion\Values\TagFilterMode;
use App\Livewire\Concerns\FiltersByTags;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ExpertList extends Component
{
    use FiltersByTags;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Persona tag values the cards are narrowed to, combined by $tagFilterMode
     * (see FiltersByTags). Kept in the URL like the search, so a filtered list
     * can be linked and survives a reload.
     *
     * @var list<int|string>
     */
    #[Url(as: 'tags')]
    public array $tagFilter = [];

    #[Url(as: 'tags_mode')]
    public string $tagFilterMode = TagFilterMode::Cut->value;

    #[On('expert_modified')]
    public function render(): mixed
    {
        $search = trim($this->search);
        $tagCategories = TagCategory::ofScope(TagScope::Persona)->with('values')->get();
        $filter = $this->normaliseFilterState($tagCategories);

        $experts = Expert::query()
            ->with('tagValues.category')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('role', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->where(fn ($q) => $this->applyTagFilter($q, $filter))
            ->orderBy('name')
            ->get();

        return view('livewire.experts.expert-list', [
            'experts' => $experts,
            'hasFilters' => $search !== '' || $filter->valueIds() !== [],
            'tagCategories' => $tagCategories,
            'activeTagCount' => count($filter->valueIds()),
        ]);
    }
}
