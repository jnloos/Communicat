<?php

namespace App\Services\Transfer;

use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Services\Tags\TagNames;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * All tag categories and values by name. An import creates what is missing:
 * an archive whose tags were dropped would lose what its runs meant.
 */
final class TagTransfer
{
    /** @return list<array{scope: string, name: string, values: list<string>}> */
    public function export(): array
    {
        return TagCategory::with(['values' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->orderBy('scope')->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (TagCategory $category) => [
                'scope' => $category->scope->value,
                'name' => $category->name,
                'values' => $category->values->pluck('name')->all(),
            ])
            ->values()
            ->all();
    }

    public function import(array $categories, ImportReport $report): void
    {
        $known = TagCategory::with('values')->get();

        foreach ($categories as $data) {
            try {
                $this->importOne(EntryFields::entry($data), $known, $report);
            } catch (UnreadableEntry) {
                $report->add(ImportReport::TAGS, EntryFields::label($data, 'name'), ImportReport::FAILED, __('transfer.notes.unreadable_entry'));
            }
        }
    }

    /** @param  Collection<int, TagCategory>  $known  every category with its values, extended as entries create more */
    private function importOne(array $data, Collection $known, ImportReport $report): void
    {
        $scope = TagScope::tryFrom((string) EntryFields::text($data, 'scope'));
        $name = trim((string) EntryFields::text($data, 'name'));
        $valueNames = EntryFields::texts($data, 'values');

        if ($scope === null || $name === '') {
            $report->add(ImportReport::TAGS, $name, ImportReport::FAILED, __('transfer.notes.tag_unreadable'));

            return;
        }

        $category = $known->first(fn (TagCategory $existing) => $existing->scope === $scope && TagNames::same($existing->name, $name));
        $created = $category === null;

        if ($created) {
            $category = TagCategory::create(['scope' => $scope, 'name' => $name])->setRelation('values', new EloquentCollection);
            $known->push($category);
        }

        $newValues = [];

        foreach ($valueNames as $valueName) {
            $valueName = trim($valueName);

            if ($valueName === '' || $category->values->contains(fn (TagValue $value) => TagNames::same($value->name, $valueName))) {
                continue;
            }

            $category->values->push($category->values()->create(['name' => $valueName]));
            $newValues[] = $valueName;
        }

        $report->add(
            ImportReport::TAGS,
            $name,
            $created ? ImportReport::CREATED : ImportReport::SKIPPED,
            $newValues === [] ? null : __('transfer.notes.tag_values_added', ['values' => implode(', ', $newValues)]),
        );
    }
}
