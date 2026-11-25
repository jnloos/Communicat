<?php

namespace App\Services\Study\Planning;

use App\Models\StudyWave;

/**
 * Thesis study, see docs/study.md.
 *
 * Whether a manifest may plan wave N over a scenario range (spec 1.3). A later
 * wave continues where the last one stopped, on the same core and the same
 * scenarios it planned; scenarios not planned yet may
 * still change. Asking for a wave that exists continues an interrupted apply,
 * so only the very same file and range pass.
 */
final class WaveRules
{
    /** @return list<string> why the wave is refused; empty = accepted */
    public function problems(Manifest $manifest, int $number, int $from, int $to): array
    {
        if ($number < 1 || ! self::rangeFits($manifest, $from, $to)) {
            return ["Wave {$number}, scenarios {$from} to {$to}: the wave is numbered from 1 and its range lies within scenarios 1 to ".count($manifest->scenarios()).'.'];
        }

        $existing = $this->wave($manifest->study(), $number);
        if ($existing !== null) {
            return $this->sameWave($existing, $manifest, $from, $to);
        }

        return $number === 1
            ? ($from === 1 ? [] : ['Wave 1 must start at scenario 1.'])
            : $this->nextWave($manifest, $number, $from);
    }

    /** Whether from..to is a non-empty range of the file's scenarios; the planner needs nothing more. */
    public static function rangeFits(Manifest $manifest, int $from, int $to): bool
    {
        return $from >= 1 && $from <= $to && $to <= count($manifest->scenarios());
    }

    /** @return list<string> */
    private function sameWave(StudyWave $existing, Manifest $manifest, int $from, int $to): array
    {
        $problems = [];
        if ([$existing->first_scenario, $existing->last_scenario] !== [$from, $to]) {
            $problems[] = "Wave {$existing->number} exists with a different range ({$existing->first_scenario} to {$existing->last_scenario}).";
        }
        if ($existing->manifest_sha256 !== $manifest->sha256()) {
            $problems[] = "Wave {$existing->number} exists with a different manifest.";
        }

        return $problems;
    }

    /** @return list<string> */
    private function nextWave(Manifest $manifest, int $number, int $from): array
    {
        $previous = $this->wave($manifest->study(), $number - 1);
        if ($previous === null) {
            return ['Wave '.($number - 1).' does not exist.'];
        }

        $problems = [];
        if (! $previous->isApplied()) {
            $problems[] = "Wave {$previous->number} is not applied yet.";
        }
        if ($from !== $previous->last_scenario + 1) {
            $problems[] = "Wave {$number} must start at scenario ".($previous->last_scenario + 1).'.';
        }
        if ($previous->core_sha256 !== $manifest->coreHash()) {
            $problems[] = "The manifest's core differs from wave {$previous->number}'s.";
        }

        $planned = $previous->manifest();
        for ($scenario = 1; $scenario <= $previous->last_scenario; $scenario++) {
            if ($planned->scenarioHash($scenario) !== $manifest->scenarioHash($scenario)) {
                $problems[] = "Scenario {$scenario} differs from the one wave {$previous->number} planned.";
            }
        }

        return $problems;
    }

    private function wave(string $study, int $number): ?StudyWave
    {
        return StudyWave::where('study', $study)->where('number', $number)->first();
    }
}
