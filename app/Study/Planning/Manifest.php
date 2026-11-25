<?php

namespace App\Study\Planning;

use JsonException;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * The fixed study file a wave is planned from (spec 1): model, pipelines,
 * attribute templates, name pools and scenarios. Read through fromJson() on upload,
 * which refuses anything the planner could not turn into a study.
 *
 * Three hashes over the canonical form (spec 1.3): the core (everything but the
 * scenarios) and each scenario on its own let a later wave append scenarios while
 * proving it changed none an earlier wave planned; sha256() covers the whole file.
 */
final class Manifest
{
    /** @param  array<string, mixed>  $data  decoded and validated */
    private function __construct(private readonly array $data) {}

    /**
     * A canonical copy read back from study_waves: it was validated when its wave
     * was planned, and today's model registry or pipelines must not reject it.
     *
     * @throws InvalidManifest
     */
    public static function fromStored(string $json): self
    {
        return new self(self::decode($json));
    }

    /** @throws InvalidManifest */
    public static function fromJson(string $json): self
    {
        $data = self::decode($json);

        $problems = ManifestProblems::of($data);
        if ($problems !== []) {
            throw new InvalidManifest($problems);
        }

        $manifest = new self($data);

        // Capacity: drawing every scenario of the file finds an empty pool at wave 1, not at wave 3.
        NameDraw::through($manifest, count($manifest->scenarios()));

        return $manifest;
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidManifest(['Not valid JSON: '.$exception->getMessage()]);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidManifest(['The manifest must be a JSON object.']);
        }

        return $data;
    }

    public function study(): string
    {
        return $this->data['study'];
    }

    public function masterSeed(): int
    {
        return $this->data['master_seed'];
    }

    public function model(): string
    {
        return $this->data['model'];
    }

    public function turnBudget(): int
    {
        return $this->data['turn_budget'];
    }

    /** @return list<string> */
    public function pipelines(): array
    {
        return $this->data['pipelines'];
    }

    /** "seating": "table" — seats and descriptions from SeatTable; absent, seats are drawn and the focus reads A. */
    public function seatsByTable(): bool
    {
        return ($this->data['seating'] ?? null) === 'table';
    }

    /** @return array<string, array{self: string, roster: string}> Role, Gender, Origin */
    public function attributes(): array
    {
        return $this->data['attributes'];
    }

    /** The Gender attribute's value for 'G+' or 'G-'. */
    public function genderValue(string $gender): string
    {
        return $this->data['genders'][$gender];
    }

    /** @return list<string> the subgroup codes of one ethnicity sign, in manifest order */
    public function subgroupsOf(string $ethnicity): array
    {
        return array_keys(array_filter($this->data['subgroups'], fn (array $subgroup) => $subgroup['ethnicity'] === $ethnicity));
    }

    public function country(string $subgroup): string
    {
        return $this->data['subgroups'][$subgroup]['country'];
    }

    /** @return list<array{id: string, name: string}> */
    public function firstNames(string $subgroup, string $gender): array
    {
        return $this->data['first_names'][$subgroup][$gender];
    }

    /** @return list<array{id: string, name: string}> */
    public function lastNames(string $subgroup): array
    {
        return $this->data['last_names'][$subgroup];
    }

    /** @return list<string> */
    public function excludedFullNames(): array
    {
        return $this->data['excluded_full_names'] ?? [];
    }

    /** @return list<array{id: int, topic: string, titles: array{high: string, low: string}, descriptions: array{A: string, B: string, C: string}}> */
    public function scenarios(): array
    {
        return $this->data['scenarios'];
    }

    /** How many runs the planner makes of this many scenarios: blocks × status cells × pipelines. */
    public function runsPer(int $scenarios): int
    {
        return $scenarios * Pot::BLOCKS * count(Planner::STATUS_CELLS) * count($this->pipelines());
    }

    /** @param  int  $id  1-based, as in the file */
    public function scenario(int $id): array
    {
        return $this->data['scenarios'][$id - 1];
    }

    /** The form stored in study_waves and hashed: keys sorted, lists in order. */
    public function canonical(): string
    {
        return self::canonicalize($this->data);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->canonical());
    }

    public function coreHash(): string
    {
        return hash('sha256', self::canonicalize(array_diff_key($this->data, ['scenarios' => true])));
    }

    public function scenarioHash(int $id): string
    {
        return hash('sha256', self::canonicalize($this->scenario($id)));
    }

    /** Objects sorted by key at every depth, lists kept in order: equal content, equal bytes. */
    public static function canonicalize(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
