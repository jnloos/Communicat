<?php

namespace App\Livewire\Projects;

use App\Events\ContributorsChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\ExpertSuggester;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class SelectContributors extends Component
{
    #[Locked]
    public int $forProjectId;

    protected Project $forProject;

    public string $search = '';

    public string $userSearch = '';

    /** @var array<int, int> ordered list of suggested expert ids */
    public array $suggestedIds = [];

    /** @var array<int, string> expert id => reason */
    public array $suggestionReasons = [];

    public bool $isSuggesting = false;

    public ?string $suggestionError = null;

    public function hasActiveFilters(): bool
    {
        return trim($this->search) !== '';
    }

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
        $this->forProject = $project;

        $this->loadSuggestionsFromSettings();
    }

    public function hydrate(): void
    {
        $this->refreshProject();
    }

    public function refreshProject(): void
    {
        $this->forProject = Project::find($this->forProjectId);
    }

    #[On('select_contributors')]
    public function select(): void
    {
        Flux::modal('select-contributors')->show();
    }

    public function addExpert(int $expertId): void
    {
        $expert = Expert::findOrFail($expertId);
        $this->forProject->addContributingExpert($expert);
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function removeExpert(int $expertId): void
    {
        $expert = Expert::findOrFail($expertId);
        $this->forProject->removeContributingExpert($expert);
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function addUser(int $userId): void
    {
        Gate::authorize('manage-contributors', $this->forProject);
        $user = User::findOrFail($userId);
        $this->forProject->addContributingUser($user);
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('manage-contributors', $this->forProject);
        $user = User::findOrFail($userId);
        $this->forProject->removeContributingUser($user);
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function suggestExperts(ExpertSuggester $suggester): void
    {
        $this->isSuggesting = true;
        $this->suggestionError = null;

        try {
            $results = $suggester->suggest($this->forProject, 5);

            $settings = $this->forProject->settings ?? [];
            $settings['suggested_experts'] = [
                'generated_at' => now()->toIso8601String(),
                'items' => $results,
            ];
            $this->forProject->settings = $settings;
            $this->forProject->save();

            $this->applySuggestions($results);
        } catch (Throwable $e) {
            Log::warning('Expert suggestion failed', [
                'project_id' => $this->forProjectId,
                'error' => $e->getMessage(),
            ]);
            $this->suggestionError = __('Could not generate suggestions, please retry.');
        } finally {
            $this->isSuggesting = false;
        }
    }

    public function clearSuggestions(): void
    {
        $settings = $this->forProject->settings ?? [];
        unset($settings['suggested_experts']);
        $this->forProject->settings = $settings;
        $this->forProject->save();

        $this->suggestedIds = [];
        $this->suggestionReasons = [];
        $this->suggestionError = null;
    }

    protected function loadSuggestionsFromSettings(): void
    {
        $items = $this->forProject->settings['suggested_experts']['items'] ?? [];
        if (is_array($items)) {
            $this->applySuggestions($items);
        }
    }

    /**
     * @param  array<int, array{expert_id:int, reason:string}>  $items
     */
    protected function applySuggestions(array $items): void
    {
        $ids = [];
        $reasons = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['expert_id'])) {
                continue;
            }
            $id = (int) $item['expert_id'];
            $ids[] = $id;
            $reasons[$id] = (string) ($item['reason'] ?? '');
        }

        $this->suggestedIds = $ids;
        $this->suggestionReasons = $reasons;
    }

    public function render(): mixed
    {
        $search = trim($this->search);

        $existingSuggestedIds = [];
        if (! empty($this->suggestedIds)) {
            $existingSuggestedIds = Expert::whereIn('id', $this->suggestedIds)
                ->pluck('id')
                ->all();
            $existingSuggestedIds = array_values(array_intersect($this->suggestedIds, $existingSuggestedIds));
        }

        $expertsQuery = Expert::query()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('job', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            });

        $experts = $expertsQuery->orderBy('name')->get();

        if (! empty($existingSuggestedIds)) {
            $order = array_flip($existingSuggestedIds);
            $experts = $experts->sortBy(function (Expert $e) use ($order) {
                return $order[$e->id] ?? (count($order) + 1);
            })->values();
        }

        $userSearch = trim($this->userSearch);
        $users = User::query()
            ->where('id', '!=', $this->forProject->user_id)
            ->when($userSearch !== '', function ($q) use ($userSearch) {
                $like = '%'.$userSearch.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get();

        return view('livewire.projects.select-contributors', [
            'experts' => $experts,
            'users' => $users,
            'project' => $this->forProject,
            'hasFilters' => $this->hasActiveFilters(),
            'suggestedIdSet' => array_flip($existingSuggestedIds),
            'suggestionReasons' => $this->suggestionReasons,
            'hasSuggestions' => ! empty($existingSuggestedIds),
        ]);
    }
}
