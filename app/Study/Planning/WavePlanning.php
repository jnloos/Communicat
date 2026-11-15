<?php

namespace App\Study\Planning;

use App\Models\User;
use App\Study\Models\StudyWave;
use RuntimeException;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * The one entry for study:plan and the planning page, so both check, preview
 * and write a wave the same way: prepare() → preview() → record() → apply().
 */
final class WavePlanning
{
    public function __construct(
        private readonly WaveRules $rules,
        private readonly WaveWriter $writer,
        private readonly PlanPreview $preview,
    ) {}

    public function prepare(string $json, int $number, int $from, int $to, ?string $decision): PreparedWave
    {
        $decision = trim((string) $decision) === '' ? null : trim($decision);

        try {
            $manifest = Manifest::fromJson($json);
        } catch (InvalidManifest $exception) {
            return new PreparedWave(null, $number, $from, $to, $decision, [], $exception->problems);
        }

        $problems = $this->rules->problems($manifest, $number, $from, $to, $decision);
        $plans = WaveRules::rangeFits($manifest, $from, $to) ? (new Planner)->plan($manifest, $from, $to) : [];

        if ($plans !== []) {
            $problems = [...$problems, ...$this->writer->problems($manifest, $plans)];
        }

        return new PreparedWave($manifest, $number, $from, $to, $decision, $plans, $problems);
    }

    /** @return array<string, mixed> see PlanPreview::of() */
    public function preview(PreparedWave $wave, User $owner, bool $withPrompts = true): array
    {
        return $this->preview->of($wave, $owner, $withPrompts);
    }

    /**
     * The wave's row: created, or the existing one when an interrupted apply
     * continues (WaveRules let it through only with the same file and range).
     *
     * @throws WaveRefused
     */
    public function record(PreparedWave $wave, User $owner): StudyWave
    {
        if (! $wave->isAcceptable()) {
            throw new WaveRefused($wave->problems);
        }

        return StudyWave::where('study', $wave->manifest->study())->where('number', $wave->number)->first()
            ?? StudyWave::record($wave->manifest, $wave->number, $wave->from, $wave->to, $wave->decision, $owner->id);
    }

    /**
     * Writes every run of the wave; applied_at only once all exist.
     *
     * @param  (callable(int $scenario): void)|null  $progress
     *
     * @throws WaveRefused
     */
    public function apply(StudyWave $wave, ?callable $progress = null): void
    {
        $owner = $wave->user ?? throw new RuntimeException("Wave {$wave->id} has no owner any more; plan it again as an admin.");

        $this->writer->write($wave, $owner, $progress);

        if (! $wave->isApplied()) {
            $wave->update(['applied_at' => now()]);
        }
    }
}
