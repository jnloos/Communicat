<?php

namespace App\Study\Planning;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Models\PersonaAttribute;
use App\Services\PersonaAttributes\ValueProblem;
use Normalizer;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Everything that makes a decoded manifest unusable (spec 1.2), collected, not
 * thrown at the first. Capacity is not checked here: it needs a valid manifest
 * to draw from, so Manifest::fromJson() checks it once this list is empty.
 */
final class ManifestProblems
{
    public const ATTRIBUTES = ['Role', 'Gender', 'Origin'];

    public const GENDERS = ['G+', 'G-'];

    public const ETHNICITIES = ['E+', 'E-'];

    /** Places per pot and scenario (4 focus + 8 duo); k subgroups of a sign must divide it. */
    public const PLACES_PER_POT = 12;

    /** A cast of three needs three different first and three different last names. */
    private const MIN_NAMES = 3;

    /** @var list<string> */
    private array $problems = [];

    /** @return list<string> */
    public static function of(array $data): array
    {
        $check = new self;
        $check->header($data);
        $check->seating($data);
        $check->attributes($data['attributes'] ?? null);
        $check->genders($data['genders'] ?? null);

        if ($check->subgroups($data['subgroups'] ?? null)) {
            $check->names($data);
        }

        $check->excluded($data['excluded_full_names'] ?? []);
        $check->scenarios($data['scenarios'] ?? null);
        $check->normalForm($data, 'manifest');

        return $check->problems;
    }

    private function header(array $data): void
    {
        $this->require(($data['schema'] ?? null) === 1, 'schema must be 1.');
        $study = $data['study'] ?? null;
        $this->require(is_string($study) && preg_match('/^[a-z]+$/', $study) === 1, 'study must be lower-case letters a-z only (no digits, hyphens or spaces), got '.json_encode($study, JSON_UNESCAPED_UNICODE).'.');
        $this->require(is_int($data['master_seed'] ?? null) && $data['master_seed'] > 0, 'master_seed must be a positive integer.');
        $this->require(is_int($data['turn_budget'] ?? null) && $data['turn_budget'] >= 1, 'turn_budget must be an integer of at least 1.');

        $model = $data['model'] ?? null;
        $this->require(is_string($model) && ModelConfig::exists($model), 'Unknown model '.(is_string($model) ? $model : json_encode($model)).'.');

        $pipelines = $data['pipelines'] ?? null;
        if (! $this->isListOf($pipelines, 'is_string') || $pipelines === []) {
            $this->problems[] = 'pipelines must be a non-empty list of pipeline names.';

            return;
        }

        $registry = app(PipelineRegistry::class);
        foreach ($pipelines as $pipeline) {
            $this->require($registry->has($pipeline), "Unknown pipeline {$pipeline}.");
        }
        foreach (array_unique(array_diff_assoc($pipelines, array_unique($pipelines))) as $duplicate) {
            $this->problems[] = "Duplicate pipeline {$duplicate}.";
        }
    }

    private function seating(array $data): void
    {
        if (! array_key_exists('seating', $data)) {
            return;
        }

        if ($data['seating'] !== 'table') {
            $this->problems[] = 'seating must be "table" or left out.';

            return;
        }

        $pipelines = $data['pipelines'] ?? null;
        $this->require(is_array($pipelines) && count($pipelines) === 3, 'seating "table" needs exactly 3 pipelines.');
    }

    private function attributes(mixed $attributes): void
    {
        if (! $this->hasExactKeys($attributes, self::ATTRIBUTES)) {
            $this->problems[] = 'attributes must name exactly '.implode(', ', self::ATTRIBUTES).'.';

            return;
        }

        foreach ($attributes as $name => $templates) {
            foreach (['self', 'roster'] as $kind) {
                $template = is_array($templates) ? ($templates[$kind] ?? null) : null;
                $this->require(is_string($template) && str_contains($template, PersonaAttribute::PLACEHOLDER), "Attribute {$name}: {$kind} template must contain :value.");
            }
        }
    }

    private function genders(mixed $genders): void
    {
        if (! $this->hasExactKeys($genders, self::GENDERS)) {
            $this->problems[] = 'genders must have exactly the keys G+ and G-.';

            return;
        }

        foreach ($genders as $key => $value) {
            $this->value($value, "Gender {$key}");
        }
    }

    /** @return bool whether the subgroups are sound enough to check the names against */
    private function subgroups(mixed $subgroups): bool
    {
        if (! is_array($subgroups) || $subgroups === [] || array_is_list($subgroups)) {
            $this->problems[] = 'subgroups must be a non-empty object.';

            return false;
        }

        $perSign = array_fill_keys(self::ETHNICITIES, 0);
        foreach ($subgroups as $code => $subgroup) {
            $ethnicity = is_array($subgroup) ? ($subgroup['ethnicity'] ?? null) : null;
            if (! in_array($ethnicity, self::ETHNICITIES, true)) {
                $this->problems[] = "Subgroup {$code}: ethnicity must be E+ or E-.";

                return false;
            }
            $perSign[$ethnicity]++;
            $this->value($subgroup['country'] ?? null, "Subgroup {$code}: country");
        }

        foreach ($perSign as $sign => $k) {
            $this->require($k >= 1 && self::PLACES_PER_POT % $k === 0, "{$k} subgroups with ethnicity {$sign}: there must be at least one, and 12 must divide by their number.");
        }

        return true;
    }

    private function names(array $data): void
    {
        $ids = [];

        foreach (array_keys($data['subgroups']) as $code) {
            $lists = [["Subgroup {$code}", 'last names', $data['last_names'][$code] ?? null]];
            foreach (self::GENDERS as $gender) {
                $lists[] = ["Subgroup {$code}, {$gender}", 'first names', $data['first_names'][$code][$gender] ?? null];
            }

            foreach ($lists as [$label, $noun, $list]) {
                if (! $this->isListOf($list, fn ($entry) => is_array($entry) && is_string($entry['id'] ?? null) && is_string($entry['name'] ?? null)) || count($list) < self::MIN_NAMES) {
                    $this->problems[] = "{$label}: at least ".self::MIN_NAMES." {$noun}, each with id and name.";

                    continue;
                }

                $names = array_column($list, 'name');
                foreach ($names as $name) {
                    $this->value($name, "{$label}, {$noun}: name");
                }
                foreach (array_unique(array_diff_assoc($names, array_unique($names))) as $duplicate) {
                    $this->problems[] = "{$label}, {$noun}: {$duplicate} appears twice.";
                }
                array_push($ids, ...array_column($list, 'id'));
            }

            $this->fullNames($data, $code);
        }

        foreach (array_unique(array_diff_assoc($ids, array_unique($ids))) as $duplicate) {
            $this->problems[] = "Duplicate name id {$duplicate}.";
        }
    }

    /** Every pair a draw can make must fit in an attribute value; checking the longest of each is enough (line breaks and empty names are checked name by name). */
    private function fullNames(array $data, string $code): void
    {
        $longest = fn (mixed $list) => is_array($list) ? collect($list)->pluck('name')->filter(fn ($name) => is_string($name))->sortByDesc(fn ($name) => mb_strlen($name))->first() : null;
        $last = $longest($data['last_names'][$code] ?? null);

        foreach (self::GENDERS as $gender) {
            $first = $longest($data['first_names'][$code][$gender] ?? null);
            if ($first !== null && $last !== null) {
                $this->require(ValueProblem::of("{$first} {$last}") !== ValueProblem::TooLong, "Subgroup {$code}, {$gender}: full name: ".ValueProblem::TooLong->message());
            }
        }
    }

    private function excluded(mixed $excluded): void
    {
        $this->require($this->isListOf($excluded, 'is_string'), 'excluded_full_names must be a list of names.');
    }

    private function scenarios(mixed $scenarios): void
    {
        if (! is_array($scenarios) || ! array_is_list($scenarios) || $scenarios === []) {
            $this->problems[] = 'scenarios must be a non-empty list.';

            return;
        }

        foreach ($scenarios as $index => $scenario) {
            $position = $index + 1;
            if (! is_array($scenario) || ($scenario['id'] ?? null) !== $position) {
                $this->problems[] = "Scenario at position {$position} has id ".json_encode(is_array($scenario) ? ($scenario['id'] ?? null) : null).", expected {$position}.";

                continue;
            }

            $this->scenario($scenario, "Scenario {$position}");
        }
    }

    private function scenario(array $scenario, string $label): void
    {
        $this->require(is_string($scenario['topic'] ?? null) && $scenario['topic'] !== '', "{$label}: topic must not be empty.");

        $titles = $scenario['titles'] ?? null;
        foreach (['high', 'low'] as $status) {
            $this->value(is_array($titles) ? ($titles[$status] ?? null) : null, "{$label}: title {$status}");
        }
        if (is_array($titles) && isset($titles['high'], $titles['low']) && $titles['high'] === $titles['low']) {
            $this->problems[] = "{$label}: titles high and low are the same.";
        }

        $descriptions = $scenario['descriptions'] ?? null;
        $present = [];
        foreach (['A', 'B', 'C'] as $letter) {
            $text = is_array($descriptions) ? ($descriptions[$letter] ?? null) : null;
            if (is_string($text) && $text !== '') {
                $present[] = $text;
            } else {
                $this->problems[] = "{$label}: description {$letter} must not be empty.";
            }
        }
        if (count($present) === 3 && count(array_unique($present)) < 3) {
            $this->problems[] = "{$label}: descriptions A, B and C must differ.";
        }
    }

    /** A text that becomes an attribute value: non-empty, one line, short enough (ValueProblem). */
    private function value(mixed $value, string $label): void
    {
        if (! is_string($value) || $value === '') {
            $this->problems[] = "{$label} must not be empty.";

            return;
        }

        $problem = ValueProblem::of($value);
        $this->require($problem === null, "{$label}: ".$problem?->message());
    }

    /** Trimmed and NFC everywhere, refused rather than repaired: the hash must mean what the file says. */
    private function normalForm(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->normalForm($item, "{$path}.{$key}");
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $this->require(trim($value) === $value, "{$path} is not trimmed.");
        $this->require(Normalizer::isNormalized($value, Normalizer::FORM_C), "{$path} is not in Unicode NFC.");
    }

    private function hasExactKeys(mixed $value, array $keys): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function isListOf(mixed $value, callable $check): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, $check) === $value;
    }

    private function require(bool $condition, string $problem): void
    {
        if (! $condition) {
            $this->problems[] = $problem;
        }
    }
}
