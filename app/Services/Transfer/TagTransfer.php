<?php

namespace App\Services\Transfer;

use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Services\Tags\TagNames;

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
        foreach ($categories as $data) {
            $scope = TagScope::tryFrom((string) ($data['scope'] ?? ''));
            $name = trim((string) ($data['name'] ?? ''));

            if ($scope === null || $name === '') {
                $report->add(ImportReport::TAGS, $name, ImportReport::FAILED, __('transfer.notes.tag_unreadable'));

                continue;
            }

            $category = TagCategory::ofScope($scope)->get()->first(fn (TagCategory $existing) => TagNames::same($existing->name, $name));
            $created = $category === null;
            $category ??= TagCategory::create(['scope' => $scope, 'name' => $name]);
            $newValues = [];

            foreach ($data['values'] ?? [] as $valueName) {
                $valueName = trim((string) $valueName);

                if ($valueName === '' || $category->values()->get()->contains(fn (TagValue $value) => TagNames::same($value->name, $valueName))) {
                    continue;
                }

                $category->values()->create(['name' => $valueName]);
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
}
