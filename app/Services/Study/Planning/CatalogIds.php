<?php

namespace App\Services\Study\Planning;

use App\Discussion\Values\AttributeVisibility;

/**
 * Thesis study, see docs/study.md.
 *
 * What Catalog::ensure() found or created, as the ids syncTags(),
 * syncAttributeValues() and setAttributeVisibilities() take.
 */
final class CatalogIds
{
    /**
     * @param  array<string, array{0: int, 1: array<string, int>}>  $projectTags  config key => [category id, value name => value id]
     * @param  array<string, array{0: int, 1: array<string, int>}>  $personaTags  the same for persona tags
     * @param  array<string, int>  $attributes  Role, Gender, Origin => definition id
     * @param  array<int, AttributeVisibility>  $visibilities  every definition id => level
     */
    public function __construct(
        private readonly array $projectTags,
        private readonly array $personaTags,
        private readonly array $attributes,
        public readonly array $visibilities,
    ) {}

    /** @return array<int, int> category id => value id */
    public function projectTags(RunPlan $run, int $wave): array
    {
        return self::selection($this->projectTags, $run->tags() + ['wave' => (string) $wave]);
    }

    /** @return array<int, int> category id => value id */
    public function personaTags(PersonaPlan $persona): array
    {
        return self::selection($this->personaTags, $persona->tags());
    }

    /** @return array<int, string> definition id => value */
    public function attributeValues(PersonaPlan $persona): array
    {
        $values = [];
        foreach ($persona->attributes as $name => $value) {
            $values[$this->attributes[$name]] = $value;
        }

        return $values;
    }

    private static function selection(array $categories, array $tags): array
    {
        $selection = [];
        foreach ($tags as $key => $value) {
            [$categoryId, $valueIds] = $categories[$key];
            $selection[$categoryId] = $valueIds[$value];
        }

        return $selection;
    }
}
