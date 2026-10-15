<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\Dimension;
use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\RunPlacement;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\TurnWindow;
use Illuminate\Support\Arr;

/**
 * Gathers the runs of one answer into panels and groups while QuestionEvaluator
 * walks them, then lays the answer out: panels and groups in their dimension's
 * order, the baseline group first, a panel or group no run reached left out.
 * Mutable, and never leaves the evaluator.
 */
final class PanelCollector
{
    /** @var array<string, array<int|string, GroupCollector>> panel key => group key => collector */
    private array $groups = [];

    /** @var array<string, int> panel key => runs that counted in it */
    private array $runs = [];

    /** @var array<string, list<int|string>> panel key => its value in each split dimension */
    private array $parts = [];

    private int $excluded = 0;

    /** @param  array<int|string, array{0: RunValue, 1: array<int, float>}>  $read  group key => [its value, its curve] */
    public function add(RunPlacement $place, array $read): void
    {
        $this->runs[$place->panelKey] = ($this->runs[$place->panelKey] ?? 0) + 1;
        $this->parts[$place->panelKey] = $place->parts;

        foreach ($read as $group => [$runValue, $curve]) {
            ($this->groups[$place->panelKey][$group] ??= new GroupCollector)->add($runValue, $curve);
        }
    }

    /** A run that has no value in the answer. */
    public function exclude(): void
    {
        $this->excluded++;
    }

    /** @param  int  $runs  every run the project filter matched, counted or not */
    public function result(QuestionDefinition $question, DimensionValues $values, int $runs, TurnWindow $window): QuestionResult
    {
        $groupKeys = array_merge([], ...array_map('array_keys', array_values($this->groups)));
        [$groupLabels, $baseline] = $this->baselineFirst($this->labels($values, $question->compareBy, $groupKeys), $question);
        $splitLabels = $this->splitLabels($values, $question->splitBy);

        return new QuestionResult(
            $this->panels($splitLabels, $groupLabels),
            array_combine(array_map('strval', array_keys($groupLabels)), $groupLabels),
            $runs,
            $this->excluded,
            $window->turns,
            $window->isCapped(),
            $window->shortestRun,
            $baseline,
            columns: count($splitLabels) === 2 ? $splitLabels[1] : [],
        );
    }

    /**
     * The groups with the baseline moved to the front, and the baseline's key:
     * the one asked for when a group has it, else the first group in dimension
     * order. Null without a compare dimension or without any group.
     *
     * @param  array<int|string, string>  $labels
     * @return array{0: array<int|string, string>, 1: ?string}
     */
    private function baselineFirst(array $labels, QuestionDefinition $question): array
    {
        if ($question->compareBy === null || $labels === []) {
            return [$labels, null];
        }

        $baseline = $question->baseline !== null && array_key_exists($question->baseline, $labels)
            ? $question->baseline
            : array_key_first($labels);

        return [[$baseline => $labels[$baseline]] + $labels, (string) $baseline];
    }

    /**
     * @param  list<array<int|string, string>>  $splitLabels
     * @param  array<int|string, string>  $groupLabels
     * @return list<Panel>
     */
    private function panels(array $splitLabels, array $groupLabels): array
    {
        $panels = [];

        foreach ($this->combinations($splitLabels) as $combination) {
            $panelKey = implode(Panel::KEY_SEPARATOR, array_column($combination, 0));

            if (isset($this->groups[$panelKey])) {
                $panels[] = $this->panel($panelKey, $combination, $groupLabels);
            }
        }

        return $panels;
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $combination  [value key, label] per split dimension
     * @param  array<int|string, string>  $groupLabels
     */
    private function panel(string $panelKey, array $combination, array $groupLabels): Panel
    {
        $groups = [];

        foreach ($groupLabels as $groupKey => $groupLabel) {
            if (isset($this->groups[$panelKey][$groupKey])) {
                $groups[] = $this->groups[$panelKey][$groupKey]->result((string) $groupKey, $groupLabel);
            }
        }

        $twoWay = count($combination) === 2;

        return new Panel(
            key: $panelKey,
            label: implode(Panel::LABEL_SEPARATOR, array_column($combination, 1)),
            groups: $groups,
            runs: $this->runs[$panelKey],
            compareCount: count($groupLabels),
            row: $twoWay ? (string) $combination[0][0] : '',
            rowLabel: $twoWay ? $combination[0][1] : '',
            column: $twoWay ? (string) $combination[1][0] : '',
            columnLabel: $twoWay ? $combination[1][1] : '',
        );
    }

    /**
     * Per split dimension the labels of its values that occur in a panel, in
     * the dimension's own order.
     *
     * @param  list<Dimension>  $splitBy
     * @return list<array<int|string, string>>
     */
    private function splitLabels(DimensionValues $values, array $splitBy): array
    {
        return array_map(
            fn (Dimension $dimension, int $index) => $this->labels($values, $dimension, array_values(array_unique(array_column($this->parts, $index)))),
            $splitBy,
            array_keys($splitBy),
        );
    }

    /**
     * Every combination of the dimensions' values, the first dimension
     * varying slowest; one empty combination without a split.
     *
     * @param  list<array<int|string, string>>  $splitLabels
     * @return list<list<array{0: int|string, 1: string}>> per combination its [value key, label] per dimension
     */
    private function combinations(array $splitLabels): array
    {
        $pairs = array_map(
            fn (array $labels) => array_map(fn (int|string $key, string $label) => [$key, $label], array_keys($labels), $labels),
            $splitLabels,
        );

        return Arr::crossJoin(...$pairs);
    }

    /**
     * Labels for the keys that occur, in the dimension's own order. A key the
     * dimension no longer lists (a pipeline class since removed) keeps its key
     * as label rather than vanishing from the answer.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    private function labels(DimensionValues $values, ?Dimension $dimension, array $keys): array
    {
        $present = array_flip($keys);
        $known = $dimension === null ? ['' => ''] : $values->labels($dimension);
        $labels = array_intersect_key($known, $present);

        foreach (array_keys(array_diff_key($present, $labels)) as $key) {
            $labels[$key] = (string) $key;
        }

        return $labels;
    }
}
