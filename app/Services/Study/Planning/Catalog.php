<?php

namespace App\Services\Study\Planning;

use App\Discussion\Values\AttributeVisibility;
use App\Models\PersonaAttribute;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * Thesis study, see docs/study.md.
 *
 * The tag categories, tag values and attribute definitions a wave writes with
 * (spec 3): matched by name like everywhere else, created where missing, never
 * changed. A definition whose templates differ from the manifest's refuses the
 * wave, because started runs have frozen the existing templates and the
 * prompts would no longer be the ones the manifest describes.
 */
final class Catalog
{
    /** @return list<string> */
    public function problems(Manifest $manifest): array
    {
        $problems = [];

        foreach ($manifest->attributes() as $name => $templates) {
            $wanted = [$templates['self'], $templates['roster']];
            $existing = $this->definition($name);
            // A missing Role is created by PersonaAttribute::role(), with its default templates.
            $actual = match (true) {
                $existing !== null => [$existing->self_template, $existing->roster_template],
                $name === PersonaAttribute::ROLE => [PersonaAttribute::ROLE_SELF_TEMPLATE, PersonaAttribute::ROLE_ROSTER_TEMPLATE],
                default => $wanted,
            };

            if ($actual !== $wanted) {
                $problems[] = "Attribute {$name}: this database uses \"{$actual[0]}\" / \"{$actual[1]}\", the manifest \"{$wanted[0]}\" / \"{$wanted[1]}\".";
            }
        }

        return $problems;
    }

    /**
     * Run inside a transaction; call problems() first.
     *
     * @param  list<RunPlan>  $plans
     */
    public function ensure(Manifest $manifest, array $plans, int $wave): CatalogIds
    {
        $attributes = $this->attributes($manifest);

        return new CatalogIds(
            projectTags: $this->tags(TagScope::Project, config('study.tags'), $this->projectValues($plans, $wave)),
            personaTags: $this->tags(TagScope::Persona, config('study.persona_tags'), $this->personaValues($plans)),
            attributes: $attributes,
            visibilities: PersonaAttribute::pluck('id')->mapWithKeys(fn (int $id) => [
                $id => in_array($id, $attributes, true) ? AttributeVisibility::All : AttributeVisibility::None,
            ])->all(),
        );
    }

    /** @return array<string, int> */
    private function attributes(Manifest $manifest): array
    {
        $ids = [];
        foreach ($manifest->attributes() as $name => $templates) {
            $definition = $name === PersonaAttribute::ROLE
                ? PersonaAttribute::role()
                : $this->definition($name) ?? PersonaAttribute::create(['name' => $name, 'self_template' => $templates['self'], 'roster_template' => $templates['roster']]);
            $ids[$name] = $definition->id;
        }

        return $ids;
    }

    private function definition(string $name): ?PersonaAttribute
    {
        return PersonaAttribute::ordered()->get()->first(fn (PersonaAttribute $attribute) => TagNames::same($attribute->name, $name));
    }

    /**
     * @param  array<string, string>  $names  config key => category name
     * @param  array<string, list<string>>  $values  config key => value names, in the order to create them
     */
    private function tags(TagScope $scope, array $names, array $values): array
    {
        $categories = TagCategory::ofScope($scope)->with('values')->get();
        $ids = [];

        foreach ($values as $key => $wanted) {
            $category = $categories->first(fn (TagCategory $category) => TagNames::same($category->name, $names[$key]))
                ?? TagCategory::create(['scope' => $scope, 'name' => $names[$key]])->setRelation('values', new Collection);
            $ids[$key] = [$category->id, $this->valueIds($category, $wanted)];
        }

        return $ids;
    }

    /** @return array<string, int> value name => id; a new value goes to the end */
    private function valueIds(TagCategory $category, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $value = $category->values->first(fn ($value) => TagNames::same($value->name, $name))
                ?? $category->values()->create(['name' => $name]);
            $ids[$name] = $value->id;
        }

        return $ids;
    }

    /** @return array<string, list<string>> */
    private function projectValues(array $plans, int $wave): array
    {
        // Canonical order P0..P3 × S±: the progress page lays out its grid by value position.
        $profiles = [];
        foreach (Pot::cases() as $pot) {
            $profiles[] = $pot->profile('h');
            $profiles[] = $pot->profile('l');
        }

        return [
            'study' => $this->distinct($plans, fn (RunPlan $run) => $run->study),
            'wave' => [(string) $wave],
            'scenario' => $this->distinct($plans, fn (RunPlan $run) => $run->tags()['scenario']),
            'block' => $this->distinct($plans, fn (RunPlan $run) => $run->tags()['block']),
            'focus' => $profiles,
            'duo' => $profiles,
            'focus_seat' => ['1', '2', '3'],
        ];
    }

    /** @return array<string, list<string>> */
    private function personaValues(array $plans): array
    {
        $personas = collect($plans)->flatMap(fn (RunPlan $run) => array_values($run->personas));

        return [
            'study' => $this->distinct($plans, fn (RunPlan $run) => $run->study),
            'first_name' => $this->distinct($personas->all(), fn (PersonaPlan $persona) => $persona->firstNameId),
            'subgroup' => $this->distinct($personas->all(), fn (PersonaPlan $persona) => $persona->subgroup),
            'focus' => ['true', 'false'],
        ];
    }

    /** @return list<string> naturally sorted, so S2 comes before S10 */
    private function distinct(array $items, callable $value): array
    {
        $values = array_values(array_unique(array_map($value, $items)));
        sort($values, SORT_NATURAL);

        return $values;
    }
}
