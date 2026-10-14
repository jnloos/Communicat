<?php

namespace App\Services\Transfer;

use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Tags\TagNames;
use Illuminate\Database\Eloquent\Collection;

/**
 * A question's definition names tag categories, tag values and the user's
 * groups by id. Ids differ between databases, so a definition travels by
 * names and is read back into this database's ids; a name that does not
 * resolve falls out, as a deleted tag value does on the statistics page.
 * A field of the wrong type in a file's definition throws UnreadableEntry.
 *
 * One instance per export or import: the tags and the user's groups are read
 * once and serve every question.
 */
final class DefinitionNames
{
    /** @var Collection<int, TagCategory> by id, values loaded */
    private readonly Collection $categories;

    /** @var Collection<int, ProjectGroup> by id */
    private readonly Collection $groups;

    public function __construct(User $user)
    {
        $this->categories = TagCategory::with('values')->get()->keyBy('id');
        $this->groups = ProjectGroup::where('user_id', $user->id)->get()->keyBy('id');
    }

    public function toNames(array $definition): array
    {
        $named = $definition;
        $named['people']['tags'] = $this->tagsToNames($definition['people']['tags'] ?? []);
        $named['projects']['tags'] = $this->tagsToNames($definition['projects']['tags'] ?? []);
        $named['projects']['groups'] = collect($definition['projects']['groups'] ?? [])->map(fn ($id) => $this->groups->get((int) $id)?->name)->filter()->values()->all();
        $named['compare_by'] = $this->dimensionToName($definition['compare_by'] ?? null);
        $named['split_by'] = $this->splitsToNames(self::storedSplits($definition['split_by'] ?? []));
        $named['baseline'] = $this->baselineToName($definition);

        return $named;
    }

    public function toIds(array $named): array
    {
        $people = EntryFields::map($named, 'people');
        $projects = EntryFields::map($named, 'projects');
        $groupNames = EntryFields::texts($projects, 'groups');

        $definition = $named;
        $definition['people'] = $people;
        $definition['projects'] = $projects;
        $definition['people']['tags'] = $this->tagsToIds(EntryFields::map($people, 'tags'), TagScope::Persona);
        $definition['projects']['tags'] = $this->tagsToIds(EntryFields::map($projects, 'tags'), TagScope::Project);
        $definition['projects']['groups'] = $this->groups->filter(fn (ProjectGroup $group) => in_array($group->name, $groupNames, true))->keys()->values()->all();
        $definition['compare_by'] = $this->dimensionToKey(EntryFields::text($named, 'compare_by'));
        $definition['split_by'] = $this->splitsToKeys($this->splitNames($named));
        EntryFields::assertText($named, 'baseline');
        $definition['baseline'] = $this->baselineToId($named, $definition['compare_by']);

        return $definition;
    }

    /**
     * A stored definition's split keys: a list, or one key as saved before
     * splits were lists.
     *
     * @return list<mixed>
     */
    private static function storedSplits(mixed $splitBy): array
    {
        return array_values(array_filter((array) $splitBy, fn ($key) => $key !== null && $key !== ''));
    }

    /**
     * @param  list<mixed>  $keys  dimension keys in this database
     * @return list<string> their names, those that resolve to none dropped
     */
    private function splitsToNames(array $keys): array
    {
        $names = array_map(fn ($key) => $this->dimensionToName((string) $key), $keys);

        return array_values(array_filter($names, fn (string $name) => $name !== ''));
    }

    /**
     * @param  list<string>  $names  dimension names from a file
     * @return list<string> their keys in this database, unresolved names dropped
     */
    private function splitsToKeys(array $names): array
    {
        $keys = array_map(fn (string $name) => $this->dimensionToKey($name), $names);

        return array_values(array_filter($keys, fn (string $key) => $key !== ''));
    }

    /**
     * A file's split names: a list of texts, or one text from a bundle written
     * before splits were lists. Anything else is UnreadableEntry.
     *
     * @return list<string>
     */
    private function splitNames(array $named): array
    {
        return is_array($named['split_by'] ?? null)
            ? EntryFields::texts($named, 'split_by')
            : array_values(array_filter([EntryFields::text($named, 'split_by') ?? ''], fn (string $name) => $name !== ''));
    }

    /**
     * @param  array<int|string, mixed>  $tags  category id => value ids
     * @return array<string, list<string>> category name => value names
     */
    private function tagsToNames(array $tags): array
    {
        $named = [];

        foreach ($tags as $categoryId => $valueIds) {
            $category = $this->categories->get((int) $categoryId);

            if ($category !== null) {
                $named[$category->name] = $category->values->whereIn('id', array_map('intval', (array) $valueIds))->pluck('name')->values()->all();
            }
        }

        return $named;
    }

    /**
     * @param  array<int|string, mixed>  $tags  category name => value names
     * @return array<int, list<int>> category id => value ids
     */
    private function tagsToIds(array $tags, TagScope $scope): array
    {
        $ids = [];

        foreach ($tags as $categoryName => $valueNames) {
            $valueNames = EntryFields::texts(['values' => is_array($valueNames) ? $valueNames : [$valueNames]], 'values');
            $found = $this->category($scope, (string) $categoryName);
            $valueIds = $found === null ? [] : $found->values
                ->filter(fn ($value) => collect($valueNames)->contains(fn (string $name) => TagNames::same($value->name, $name)))
                ->pluck('id')->values()->all();

            if ($valueIds !== []) {
                $ids[$found->id] = $valueIds;
            }
        }

        return $ids;
    }

    private function category(TagScope $scope, string $name): ?TagCategory
    {
        return $this->categories->first(fn (TagCategory $candidate) => $candidate->scope === $scope && TagNames::same($candidate->name, $name));
    }

    private function dimensionToName(?string $key): string
    {
        $dimension = Dimension::fromKey($key);

        if ($dimension === null) {
            return '';
        }

        return $dimension->type->isTag()
            ? $dimension->type->value.':'.($this->categories->get($dimension->categoryId)?->name ?? '')
            : $dimension->key();
    }

    private function dimensionToKey(?string $key): string
    {
        [$type, $name] = array_pad(explode(':', (string) $key, 2), 2, null);
        $type = DimensionType::tryFrom((string) $type);

        if ($type === null) {
            return '';
        }

        if (! $type->isTag()) {
            return $type->value;
        }

        $found = $this->category($type === DimensionType::PersonaTag ? TagScope::Persona : TagScope::Project, (string) $name);

        return $found === null ? '' : "{$type->value}:{$found->id}";
    }

    private function baselineToName(array $definition): ?string
    {
        $baseline = $definition['baseline'] ?? null;
        $dimension = Dimension::fromKey($definition['compare_by'] ?? null);

        if ($baseline === null || $dimension === null || $baseline === '') {
            return $baseline;
        }

        return match (true) {
            $dimension->type->isTag() => $this->categories->get($dimension->categoryId)?->values->firstWhere('id', (int) $baseline)?->name,
            $dimension->type === DimensionType::Group => $this->groups->get((int) $baseline)?->name,
            default => (string) $baseline,
        };
    }

    private function baselineToId(array $named, string $compareBy): ?string
    {
        $baseline = $named['baseline'] ?? null;
        $dimension = Dimension::fromKey($compareBy);

        if ($baseline === null || $dimension === null || $baseline === '') {
            return $baseline;
        }

        $id = match (true) {
            $dimension->type->isTag() => $this->categories->get($dimension->categoryId)?->values->first(fn ($value) => TagNames::same($value->name, (string) $baseline))?->id,
            $dimension->type === DimensionType::Group => $this->groups->firstWhere('name', $baseline)?->id,
            default => $baseline,
        };

        return $id === null ? null : (string) $id;
    }
}
