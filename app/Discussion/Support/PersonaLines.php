<?php

namespace App\Discussion\Support;

use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * The attribute text one expert contributes to a prompt: its own sentences for
 * the persona block, one roster phrase for the participants list. Every
 * attribute has its own visibility in the project (none, own, all).
 *
 * Once the project has a run_config that lists the expert, only the lines frozen
 * there count -- never the live templates -- so editing a template later changes
 * no run that has started. An expert the snapshot does not know (seated later)
 * reads the live definitions.
 */
final class PersonaLines
{
    /**
     * @param  list<array{attribute: string, value: string, self_line: string, roster: string}>  $entries
     * @param  array<string, AttributeVisibility>  $visibilities  attribute name => level
     */
    private function __construct(
        private readonly array $entries,
        private readonly array $visibilities,
    ) {}

    /** The project is required on purpose: a prompt rendered without one must fail, not silently drop the attributes. */
    public static function for(Project $project, Expert $expert): self
    {
        $seat = self::frozenSeat($project, $expert);

        if ($seat === null) {
            return new self(self::snapshotFor($expert), self::visibilitiesOf($project));
        }

        $entries = is_array($seat['attributes'] ?? null) ? $seat['attributes'] : [];
        $legacyRole = self::legacyRole($seat, $entries);

        if ($legacyRole === null) {
            return new self($entries, self::visibilitiesOf($project));
        }

        $definitions = PersonaAttribute::ordered()->get();
        $role = self::legacyRoleEntry($legacyRole, $definitions->first(fn (PersonaAttribute $definition) => $definition->isRole()));

        // The fixed role line of those runs showed the role to everyone whatever the
        // attribute level was, so the bridged entry is shown at `all` and nothing else changes.
        return new self(
            [$role, ...$entries],
            [$role['attribute'] => AttributeVisibility::All] + self::visibilitiesOf($project, $definitions),
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
        return $this->linesWhere(fn (AttributeVisibility $level) => $level->showsOwnLines(), 'self_line');
    }

    /** What follows the name in the participants list: '' or ' (Teacher, woman, from Owerri, Nigeria)'. */
    public function rosterLabel(): string
    {
        $text = $this->rosterText();

        return $text === null ? '' : " ({$text})";
    }

    public function rosterText(): ?string
    {
        $texts = $this->linesWhere(fn (AttributeVisibility $level) => $level->showsRoster(), 'roster');

        return $texts === [] ? null : implode(', ', $texts);
    }

    /** @return list<string> */
    private function linesWhere(callable $shown, string $field): array
    {
        $lines = [];

        foreach ($this->entries as $entry) {
            if ($shown($this->visibilities[$entry['attribute']] ?? AttributeVisibility::default())) {
                $lines[] = $entry[$field];
            }
        }

        return $lines;
    }

    /** The cast entry of this expert in the frozen run_config, or null when there is no snapshot or it lacks the expert. */
    private static function frozenSeat(Project $project, Expert $expert): ?array
    {
        foreach ($project->run_config['cast'] ?? [] as $seat) {
            if (($seat['expert_id'] ?? null) === $expert->id && (array_key_exists('attributes', $seat) || array_key_exists('role', $seat))) {
                return $seat;
            }
        }

        return null;
    }

    /**
     * The role text of a run frozen while the role was still a fixed field: the cast
     * entry has `role` and its attributes list no Role entry. Null for every run
     * frozen since (no `role` key), which therefore costs no query here.
     *
     * @param  list<array{attribute: string}>  $entries
     */
    private static function legacyRole(array $seat, array $entries): ?string
    {
        $role = trim((string) ($seat['role'] ?? ''));

        if ($role === '' || collect($entries)->contains(fn ($entry) => TagNames::same((string) ($entry['attribute'] ?? ''), PersonaAttribute::ROLE))) {
            return null;
        }

        return $role;
    }

    /** The Role entry such a run would have frozen, from the Role definition's templates (the defaults when this database has none). */
    private static function legacyRoleEntry(string $role, ?PersonaAttribute $definition): array
    {
        $definition ??= new PersonaAttribute([
            'name' => PersonaAttribute::ROLE,
            'self_template' => PersonaAttribute::ROLE_SELF_TEMPLATE,
            'roster_template' => PersonaAttribute::ROLE_ROSTER_TEMPLATE,
        ]);

        return [
            'attribute' => $definition->name,
            'value' => $role,
            'self_line' => $definition->selfLine($role),
            'roster' => $definition->rosterText($role),
        ];
    }

    /**
     * What the project's prompts reveal, by attribute name: the levels frozen with the
     * run, else the project's own settings. A frozen map is {name: level}; a run frozen
     * before levels were per attribute holds one string that counts for every attribute.
     * A level that is none of ours (a damaged or foreign run_config; our own code only
     * ever writes enum values) reads as the default, none, rather than throwing, so one
     * bad run cannot take the study export down with it.
     *
     * @param  Collection<int, PersonaAttribute>|null  $definitions  every definition, in order; read only when the answer needs them
     * @return array<string, AttributeVisibility>
     */
    public static function visibilitiesOf(Project $project, ?Collection $definitions = null): array
    {
        $frozen = $project->run_config['attribute_visibility'] ?? null;

        if (is_array($frozen)) {
            return array_map(fn ($level) => (is_string($level) ? AttributeVisibility::tryFrom($level) : null) ?? AttributeVisibility::default(), $frozen);
        }

        if ($frozen === null) {
            return self::liveVisibilitiesOf($project, $definitions);
        }

        $level = (is_string($frozen) ? AttributeVisibility::tryFrom($frozen) : null) ?? AttributeVisibility::default();
        $definitions ??= PersonaAttribute::ordered()->get();

        return $definitions->mapWithKeys(fn (PersonaAttribute $definition) => [$definition->name => $level])->all();
    }

    /**
     * The project's settings as they are now, by attribute name -- what TurnRunner
     * freezes. The ids are resolved by Project::attributeVisibilities(), the one place.
     *
     * @param  Collection<int, PersonaAttribute>|null  $definitions  every definition, in order; read once when null
     * @return array<string, AttributeVisibility>
     */
    public static function liveVisibilitiesOf(Project $project, ?Collection $definitions = null): array
    {
        $definitions ??= PersonaAttribute::ordered()->get();
        $byId = $project->attributeVisibilities($definitions);

        return $definitions
            ->mapWithKeys(fn (PersonaAttribute $definition) => [$definition->name => $byId[$definition->id]])
            ->all();
    }
}
