<?php

namespace App\Services\Transfer;

use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Tags\TagNames;

/**
 * A question's definition names tag categories, tag values and the user's
 * groups by id. Ids differ between databases, so a definition travels by
 * names and is read back into this database's ids; a name that does not
 * resolve falls out, as a deleted tag value does on the statistics page.
 * A field of the wrong type in a file's definition throws UnreadableEntry.
 */
final class DefinitionNames
{
    public static function toNames(array $definition, User $user): array
    {
        $categories = TagCategory::with('values')->get()->keyBy('id');
        $groups = ProjectGroup::where('user_id', $user->id)->pluck('name', 'id');
        $tagsToNames = function (array $tags) use ($categories): array {
            $named = [];

            foreach ($tags as $categoryId => $valueIds) {
                $category = $categories[(int) $categoryId] ?? null;

                if ($category !== null) {
                    $named[$category->name] = $category->values->whereIn('id', array_map('intval', (array) $valueIds))->pluck('name')->values()->all();
                }
            }

            return $named;
        };
        $dimensionToName = function (?string $key) use ($categories): string {
            $dimension = Dimension::fromKey($key);

            if ($dimension === null) {
                return '';
            }

            return $dimension->type->isTag()
                ? $dimension->type->value.':'.($categories->get($dimension->categoryId)?->name ?? '')
                : $dimension->key();
        };

        $named = $definition;
        $named['people']['tags'] = $tagsToNames($definition['people']['tags'] ?? []);
        $named['projects']['tags'] = $tagsToNames($definition['projects']['tags'] ?? []);
        $named['projects']['groups'] = collect($definition['projects']['groups'] ?? [])->map(fn ($id) => $groups[(int) $id] ?? null)->filter()->values()->all();
        $named['compare_by'] = $dimensionToName($definition['compare_by'] ?? null);
        $named['split_by'] = $dimensionToName($definition['split_by'] ?? null);
        $named['baseline'] = self::baselineToName($definition, $categories, $groups);

        return $named;
    }

    public static function toIds(array $named, User $user): array
    {
        $categories = TagCategory::with('values')->get();
        $groups = ProjectGroup::where('user_id', $user->id)->get();
        $category = fn (?TagScope $scope, string $name) => $categories->first(fn (TagCategory $candidate) => ($scope === null || $candidate->scope === $scope) && TagNames::same($candidate->name, $name));
        $tagsToIds = function (array $tags, TagScope $scope) use ($category): array {
            $ids = [];

            foreach ($tags as $categoryName => $valueNames) {
                $valueNames = EntryFields::texts(['values' => is_array($valueNames) ? $valueNames : [$valueNames]], 'values');
                $found = $category($scope, (string) $categoryName);
                $valueIds = $found === null ? [] : $found->values
                    ->filter(fn ($value) => collect($valueNames)->contains(fn ($name) => TagNames::same($value->name, (string) $name)))
                    ->pluck('id')->values()->all();

                if ($valueIds !== []) {
                    $ids[$found->id] = $valueIds;
                }
            }

            return $ids;
        };
        $dimensionToKey = function (?string $key) use ($category): string {
            [$type, $name] = array_pad(explode(':', (string) $key, 2), 2, null);
            $type = DimensionType::tryFrom((string) $type);

            if ($type === null) {
                return '';
            }

            if (! $type->isTag()) {
                return $type->value;
            }

            $found = $category($type === DimensionType::PersonaTag ? TagScope::Persona : TagScope::Project, (string) $name);

            return $found === null ? '' : "{$type->value}:{$found->id}";
        };

        $people = EntryFields::map($named, 'people');
        $projects = EntryFields::map($named, 'projects');
        $groupNames = EntryFields::texts($projects, 'groups');

        $definition = $named;
        $definition['people'] = $people;
        $definition['projects'] = $projects;
        $definition['people']['tags'] = $tagsToIds(EntryFields::map($people, 'tags'), TagScope::Persona);
        $definition['projects']['tags'] = $tagsToIds(EntryFields::map($projects, 'tags'), TagScope::Project);
        $definition['projects']['groups'] = $groups->filter(fn (ProjectGroup $group) => in_array($group->name, $groupNames, true))->pluck('id')->values()->all();
        $definition['compare_by'] = $dimensionToKey(EntryFields::text($named, 'compare_by'));
        $definition['split_by'] = $dimensionToKey(EntryFields::text($named, 'split_by'));
        EntryFields::text($named, 'baseline');
        $definition['baseline'] = self::baselineToId($named, $definition['compare_by'], $categories, $groups);

        return $definition;
    }

    private static function baselineToName(array $definition, $categories, $groups): ?string
    {
        $baseline = $definition['baseline'] ?? null;
        $dimension = Dimension::fromKey($definition['compare_by'] ?? null);

        if ($baseline === null || $dimension === null || $baseline === '') {
            return $baseline;
        }

        return match (true) {
            $dimension->type->isTag() => $categories->get($dimension->categoryId)?->values->firstWhere('id', (int) $baseline)?->name,
            $dimension->type === DimensionType::Group => $groups->get((int) $baseline),
            default => (string) $baseline,
        };
    }

    private static function baselineToId(array $named, string $compareBy, $categories, $groups): ?string
    {
        $baseline = $named['baseline'] ?? null;
        $dimension = Dimension::fromKey($compareBy);

        if ($baseline === null || $dimension === null || $baseline === '') {
            return $baseline;
        }

        $id = match (true) {
            $dimension->type->isTag() => $categories->firstWhere('id', $dimension->categoryId)?->values->first(fn ($value) => TagNames::same($value->name, (string) $baseline))?->id,
            $dimension->type === DimensionType::Group => $groups->firstWhere('name', $baseline)?->id,
            default => $baseline,
        };

        return $id === null ? null : (string) $id;
    }
}
