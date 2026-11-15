<?php

namespace App\Study\Planning;

use Ramsey\Uuid\Uuid;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * One planned run (spec 2.4): which project to open, with whom on which seat.
 * Its uuid derives from the run key, so finding the project again needs no
 * table of its own. The wave is not part of a plan: the same run belongs to
 * whichever wave covers its scenario, and the writer adds that tag.
 */
final class RunPlan
{
    public readonly string $key;

    public readonly string $uuid;

    /**
     * @param  'h'|'l'  $focusStatus
     * @param  'h'|'l'  $duoStatus
     * @param  array{focus: PersonaPlan, duo1: PersonaPlan, duo2: PersonaPlan}  $personas
     * @param  list<'focus'|'duo1'|'duo2'>  $seats  places in seat order: seat n is $seats[n - 1]
     */
    public function __construct(
        public readonly string $study,
        public readonly int $scenario,
        public readonly int $block,
        public readonly string $focusStatus,
        public readonly string $duoStatus,
        public readonly string $pipeline,
        public readonly int $seed,
        public readonly array $seats,
        public readonly array $personas,
        public readonly string $topic,
        public readonly string $model,
        public readonly int $turnBudget,
    ) {
        $this->key = self::keyOf($study, $scenario, $block, $focusStatus.$duoStatus, $pipeline);
        $this->uuid = Uuid::uuid5(Uuid::uuid5(Uuid::NAMESPACE_URL, 'communicat:study:run'), $this->key)->toString();
    }

    /** `main:s03:b07:hl:ScorePipeline`. A later replacement run appends `:a2`; attempt 1 has no suffix, so its uuid stays. */
    public static function keyOf(string $study, int $scenario, int $block, string $statuses, string $pipeline): string
    {
        return sprintf('%s:s%02d:b%02d:%s:%s', $study, $scenario, $block, $statuses, $pipeline);
    }

    /** @return list<PersonaPlan> in seat order, as OpenProject seats them */
    public function seating(): array
    {
        return array_map(fn (string $place) => $this->personas[$place], $this->seats);
    }

    public function seatOf(string $place): int
    {
        return array_search($place, $this->seats, true) + 1;
    }

    public function focusProfile(): string
    {
        return Pot::focusOf($this->block)->profile($this->focusStatus);
    }

    public function duoProfile(): string
    {
        return Pot::duoOf($this->block)->profile($this->duoStatus);
    }

    /** The researcher's label; it reaches no prompt (partials/project shows the description only). */
    public function title(): string
    {
        return sprintf('S%02d B%02d %s/%s %s', $this->scenario, $this->block, $this->focusProfile(), $this->duoProfile(), $this->pipeline);
    }

    /** @return array<string, string> config('study.tags') key => value, without the wave */
    public function tags(): array
    {
        return [
            'study' => $this->study,
            'scenario' => sprintf('S%02d', $this->scenario),
            'block' => sprintf('B%02d', $this->block),
            'focus' => $this->focusProfile(),
            'duo' => $this->duoProfile(),
            'focus_seat' => (string) $this->seatOf('focus'),
        ];
    }

    /** @return array<string, mixed> the project columns OpenProject takes */
    public function projectAttributes(): array
    {
        return [
            'title' => $this->title(),
            'description' => $this->topic,
            'model' => $this->model,
            'pipeline' => $this->pipeline,
            'turn_budget' => $this->turnBudget,
            'seed' => $this->seed,
        ];
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'uuid' => $this->uuid,
            ...$this->projectAttributes(),
            'tags' => $this->tags(),
            'seats' => array_map(fn (string $place) => ['place' => $place, ...$this->personas[$place]->toArray()], $this->seats),
        ];
    }
}
