<?php

namespace App\Study\Planning;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Who sits on every place of scenarios 1..n (spec 2.3): first the subgroup of
 * each place, then a full name from that subgroup's shuffled pairs.
 *
 * Always drawn from scenario 1 upwards, whatever wave asks: the names of scenario
 * s then depend only on the manifest's core and on s, so a later wave that
 * appends scenarios reproduces every earlier name.
 */
final class NameDraw
{
    public const PLACES = ['focus', 'duo1', 'duo2'];

    private readonly Draws $draws;

    /** @var array<string, true> full names handed out, across all pots */
    private array $used = [];

    /** @var array<string, true> */
    private readonly array $excluded;

    /** @var array<string, list<array{0: array{id: string, name: string}, 1: array{id: string, name: string}}>> "G+|DE" => pairs not yet handed out, in drawn order */
    private array $candidates = [];

    /** @var array<int, array<int, array<string, DrawnName>>> scenario => block => place => name */
    private array $names = [];

    private function __construct(private readonly Manifest $manifest)
    {
        $this->draws = new Draws($manifest->masterSeed());
        $this->excluded = array_fill_keys($manifest->excludedFullNames(), true);
    }

    /** @throws InvalidManifest when a pool runs dry before scenario $last is seated */
    public static function through(Manifest $manifest, int $last): self
    {
        $draw = new self($manifest);

        for ($scenario = 1; $scenario <= $last; $scenario++) {
            $draw->seat($scenario);
        }

        return $draw;
    }

    public function at(int $scenario, int $block, string $place): DrawnName
    {
        return $this->names[$scenario][$block][$place];
    }

    private function seat(int $scenario): void
    {
        $subgroups = $this->subgroups($scenario);

        for ($block = 1; $block <= Pot::BLOCKS; $block++) {
            $cast = [];
            foreach (self::PLACES as $place) {
                $pot = $place === 'focus' ? Pot::focusOf($block) : Pot::duoOf($block);
                $cast[$place] = $this->pick($pot, $subgroups[$block][$place], $cast)
                    ?? throw new InvalidManifest(["Scenario {$scenario}: no name left in pot {$pot->gender()}{$pot->ethnicity()}, subgroup {$subgroups[$block][$place]}."]);
            }
            $this->names[$scenario][$block] = $cast;
        }
    }

    /**
     * The subgroup of every place of one scenario. A focus place cycles through
     * the subgroups, so over three scenarios each pot's focus is exactly
     * balanced; the duo places take what is left of 12/k per subgroup, shuffled.
     *
     * @return array<int, array<string, string>> block => place => subgroup code
     */
    private function subgroups(int $scenario): array
    {
        $places = [];

        foreach (Pot::cases() as $pot) {
            $subgroups = $this->manifest->subgroupsOf($pot->ethnicity());
            $k = count($subgroups);
            $left = array_fill_keys($subgroups, intdiv(ManifestProblems::PLACES_PER_POT, $k));

            foreach (Pot::cases() as $duo) {
                $subgroup = $subgroups[(4 * ($scenario - 1) + $duo->value + $pot->value) % $k];
                $places[Pot::block($pot, $duo)]['focus'] = $subgroup;
                $left[$subgroup]--;
            }

            $rest = [];
            foreach ($left as $subgroup => $count) {
                array_push($rest, ...array_fill(0, $count, $subgroup));
            }
            $rest = $this->draws->shuffle($rest, 'duo-subgroups', $scenario, $pot->value);

            foreach (Pot::cases() as $focus) {
                $block = Pot::block($focus, $pot);
                $places[$block]['duo1'] = array_shift($rest);
                $places[$block]['duo2'] = array_shift($rest);
            }
        }

        return $places;
    }

    /**
     * The first pair in drawn order that is not excluded, not handed out yet, and
     * shares neither first nor last name with anyone already in this cast: two
     * "Müller" would read as a family, two "Thomas" make an address ambiguous.
     *
     * @param  array<string, DrawnName>  $cast
     */
    private function pick(Pot $pot, string $subgroup, array $cast): ?DrawnName
    {
        $gender = $pot->gender();
        $pairs = &$this->candidates["{$gender}|{$subgroup}"];
        $pairs ??= $this->pairs($gender, $subgroup);
        $firstNames = array_map(fn (DrawnName $name) => $name->firstName, $cast);
        $lastNames = array_map(fn (DrawnName $name) => $name->lastName, $cast);

        foreach ($pairs as $index => [$first, $last]) {
            $full = "{$first['name']} {$last['name']}";

            if (isset($this->excluded[$full]) || isset($this->used[$full])
                || in_array($first['name'], $firstNames, true) || in_array($last['name'], $lastNames, true)) {
                continue;
            }

            unset($pairs[$index]);
            $this->used[$full] = true;

            return new DrawnName($gender, $subgroup, $first['id'], $first['name'], $last['name']);
        }

        return null;
    }

    /** Every first × last pair of the subgroup, sorted by id, then shuffled once. */
    private function pairs(string $gender, string $subgroup): array
    {
        $byId = fn (array $a, array $b) => strcmp($a['id'], $b['id']);
        $firstNames = $this->manifest->firstNames($subgroup, $gender);
        $lastNames = $this->manifest->lastNames($subgroup);
        usort($firstNames, $byId);
        usort($lastNames, $byId);

        $pairs = [];
        foreach ($firstNames as $first) {
            foreach ($lastNames as $last) {
                $pairs[] = [$first, $last];
            }
        }

        return $this->draws->shuffle($pairs, 'names', $gender, $subgroup);
    }
}
