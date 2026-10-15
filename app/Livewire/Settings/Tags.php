<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Services\Tags\TagNames;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings → Tags: the global categories and their fixed values, for personas
 * and for projects. Admins only, checked in boot() so it covers every request —
 * the route's admin middleware only guards the first one, not Livewire's updates.
 */
class Tags extends Component
{
    use NeedsConfirmation;

    #[Url]
    public string $scope = 'persona';

    public string $newCategoryName = '';

    /** @var array<int|string, string> category id => name typed for a new value */
    public array $newValueNames = [];

    #[Locked]
    public ?int $renamingCategoryId = null;

    #[Locked]
    public ?int $renamingValueId = null;

    public string $renameTo = '';

    /** Livewire boots the component on every request, the mount and each update alike. */
    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function createCategory(): void
    {
        $scope = $this->currentScope();
        $this->newCategoryName = trim($this->newCategoryName);
        $this->validate(['newCategoryName' => $this->categoryNameRules($scope)], [], ['newCategoryName' => __('tags.fields.category')]);

        TagCategory::create(['scope' => $scope, 'name' => $this->newCategoryName]);
        $this->newCategoryName = '';
    }

    public function createValue(int $categoryId): void
    {
        $category = TagCategory::findOrFail($categoryId);
        $field = "newValueNames.{$categoryId}";
        $this->newValueNames[$categoryId] = trim((string) ($this->newValueNames[$categoryId] ?? ''));
        $this->validate([$field => $this->valueNameRules($category)], [], [$field => __('tags.fields.value')]);

        $category->values()->create(['name' => $this->newValueNames[$categoryId]]);
        $this->newValueNames[$categoryId] = '';
    }

    public function startRenamingCategory(int $categoryId): void
    {
        $category = TagCategory::findOrFail($categoryId);
        $this->cancelRenaming();
        $this->renamingCategoryId = $category->id;
        $this->renameTo = $category->name;
    }

    public function startRenamingValue(int $valueId): void
    {
        $value = TagValue::findOrFail($valueId);
        $this->cancelRenaming();
        $this->renamingValueId = $value->id;
        $this->renameTo = $value->name;
    }

    public function saveCategoryName(): void
    {
        $category = TagCategory::findOrFail((int) $this->renamingCategoryId);
        $this->renameTo = trim($this->renameTo);
        $this->validate(['renameTo' => $this->categoryNameRules($category->scope, $category->id)], [], ['renameTo' => __('tags.fields.category')]);

        $category->update(['name' => $this->renameTo]);
        $this->cancelRenaming();
    }

    public function saveValueName(): void
    {
        $value = TagValue::with('category')->findOrFail((int) $this->renamingValueId);
        $this->renameTo = trim($this->renameTo);
        $this->validate(['renameTo' => $this->valueNameRules($value->category, $value->id)], [], ['renameTo' => __('tags.fields.value')]);

        $value->update(['name' => $this->renameTo]);
        $this->cancelRenaming();
    }

    public function cancelRenaming(): void
    {
        $this->reset(['renamingCategoryId', 'renamingValueId', 'renameTo']);
        $this->resetValidation();
    }

    public function confirmDeleteCategory(int $categoryId): void
    {
        $category = TagCategory::withCount('values')->findOrFail($categoryId);
        $assignments = $category->assignmentCount();

        $this->confirmTitle = __('tags.confirm.delete_category.title');
        $this->confirmMessage = trans_choice('tags.confirm.delete_category.values', $category->values_count, ['name' => $category->name, 'count' => $category->values_count])
            .' '.trans_choice("tags.confirm.category_assignments.{$category->scope->value}", $assignments, ['count' => $assignments]);
        $this->needsConfirmation('deleteCategory', $categoryId);
    }

    public function deleteCategory(int $categoryId): void
    {
        TagCategory::findOrFail($categoryId)->delete();
    }

    public function confirmDeleteValue(int $valueId): void
    {
        $value = TagValue::with('category')->withCount('assignments')->findOrFail($valueId);

        $this->confirmTitle = __('tags.confirm.delete_value.title');
        $this->confirmMessage = trans_choice(
            "tags.confirm.value_assignments.{$value->category->scope->value}",
            $value->assignments_count,
            ['name' => $value->name, 'count' => $value->assignments_count],
        );
        $this->needsConfirmation('deleteValue', $valueId);
    }

    public function deleteValue(int $valueId): void
    {
        TagValue::findOrFail($valueId)->delete();
    }

    /** wire:sort hands over the item key and its new 0-based position. */
    public function sortCategory(int $categoryId, int $position): void
    {
        TagCategory::findOrFail($categoryId)->moveTo($position);
    }

    public function sortValue(int $valueId, int $position): void
    {
        TagValue::findOrFail($valueId)->moveTo($position);
    }

    /** Move up/down in the row menu, the way to sort without dragging: negative steps go up. */
    public function moveCategory(int $categoryId, int $steps): void
    {
        TagCategory::findOrFail($categoryId)->moveBy($steps);
    }

    public function moveValue(int $valueId, int $steps): void
    {
        TagValue::findOrFail($valueId)->moveBy($steps);
    }

    private function currentScope(): TagScope
    {
        return TagScope::tryFrom($this->scope) ?? TagScope::Persona;
    }

    /** @return array<int, mixed> */
    private function categoryNameRules(TagScope $scope, ?int $ignoreId = null): array
    {
        return ['required', 'string', 'max:50', Rule::unique('tag_categories', 'name')->where('scope', $scope->value)->ignore($ignoreId),
            // Names match ignoring case and space (TagNames), so "Status" and "status " would be one category to an import or an export column.
            function (string $attribute, mixed $value, Closure $fail) use ($scope, $ignoreId) {
                $clash = TagCategory::ofScope($scope)->whereKeyNot($ignoreId)->pluck('name')
                    ->contains(fn (string $name) => TagNames::same($name, (string) $value));

                if ($clash) {
                    $fail('validation.unique')->translate();
                }
            },
        ];
    }

    /** @return array<int, mixed> */
    private function valueNameRules(TagCategory $category, ?int $ignoreId = null): array
    {
        return ['required', 'string', 'max:50', Rule::unique('tag_values', 'name')->where('tag_category_id', $category->id)->ignore($ignoreId)];
    }

    public function render(): mixed
    {
        return view('livewire.settings.tags', [
            'scopes' => TagScope::cases(),
            'categories' => TagCategory::ofScope($this->currentScope())
                ->with(['values' => fn ($query) => $query->withCount('assignments')])
                ->get(),
        ])->title(__('tags.heading'));
    }
}
