<?php

namespace App\Discussion\Support;

use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;

/**
 * The attribute text one expert contributes to a prompt: its own sentences for
 * the persona block, one roster phrase for the participants list.
 *
 * Once the project has a run_config that lists the expert, only the lines frozen
 * there count -- never the live templates -- so editing a template later changes
 * no run that has started. An expert the snapshot does not know (seated later)
 * reads the live definitions.
 */
final class PersonaLines
{
    /**
     * @param  list<string>  $self
     * @param  list<string>  $roster
     */
    private function __construct(
        private readonly AttributeVisibility $visibility,
        private readonly array $self,
        private readonly array $roster,
    ) {}

    /** The project is required on purpose: a prompt rendered without one must fail, not silently drop the attributes. */
    public static function for(Project $project, Expert $expert): self
    {
        $frozen = self::frozenEntries($project, $expert);
        $entries = $frozen ?? self::snapshotFor($expert);

        return new self(
            self::visibilityOf($project),
            array_column($entries, 'self_line'),
            array_column($entries, 'roster'),
        );
    }

    /**
     * The rendered lines of every attribute the expert has a value for, in
     * attribute order, whatever the visibility -- what the run_config freezes.
     *
     * @return list<array{attribute: string, value: string, self_line: string, roster: string}>
     */
    public static function snapshotFor(Expert $expert): array
    {
        return $expert->personaAttributes
            ->map(fn (PersonaAttribute $attribute) => [
                'attribute' => $attribute->name,
                'value' => (string) $attribute->pivot->value,
                'self_line' => $attribute->selfLine($attribute->pivot->value),
                'roster' => $attribute->rosterText($attribute->pivot->value),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function selfLines(): array
    {
        return $this->visibility->showsOwnLines() ? $this->self : [];
    }

    /** What follows the role in the participants list: '' or ', woman, from Owerri, Nigeria'. */
    public function rosterSuffix(): string
    {
        $text = $this->rosterText();

        return $text === null ? '' : ', '.$text;
    }

    public function rosterText(): ?string
    {
        if (! $this->visibility->showsRoster() || $this->roster === []) {
            return null;
        }

        return implode(', ', $this->roster);
    }

    /** The frozen entries of this expert, or null when there is no snapshot or it lacks the expert. */
    private static function frozenEntries(Project $project, Expert $expert): ?array
    {
        foreach ($project->run_config['cast'] ?? [] as $seat) {
            if (($seat['expert_id'] ?? null) === $expert->id && array_key_exists('attributes', $seat)) {
                return $seat['attributes'];
            }
        }

        return null;
    }

    /**
     * What the project's prompts reveal: the value frozen with the run, else the project's own setting.
     * A frozen value that is no visibility (a damaged or foreign run_config; our own code only ever
     * writes enum values) reads as the default, none -- the pre-feature prompts -- rather than
     * throwing, so one bad run cannot take the study export down with it.
     */
    public static function visibilityOf(Project $project): AttributeVisibility
    {
        $frozen = $project->run_config['attribute_visibility'] ?? null;

        if ($frozen === null) {
            return $project->attribute_visibility;
        }

        return (is_string($frozen) ? AttributeVisibility::tryFrom($frozen) : null) ?? AttributeVisibility::default();
    }
}
