<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\TagFilter;
use App\Discussion\Values\TurnRange;
use App\Models\TagValue;
use App\Models\User;

/**
 * The rules that make a question as the viewer sees it fit to store, beyond
 * what QuestionDefinition::fromArray() can decide alone: each needs the
 * database or the viewer. The editor applies them; none of them saves.
 */
final class QuestionNormaliser
{
    public function __construct(
        private readonly QuestionEvaluator $evaluator,
        private readonly DimensionValues $dimensionValues,
    ) {}

    /**
     * A compared question without a baseline gets the first value of its
     * dimension, so the sign of its delta is stored, not left to the order in
     * which the values happen to be listed later.
     */
    public function withBaseline(User $viewer, QuestionDefinition $definition): QuestionDefinition
    {
        if ($definition->compareBy === null || $definition->baseline !== null) {
            return $definition;
        }

        $first = array_key_first($this->dimensionValues->forUser($viewer)->labels($definition->compareBy));

        return $first === null ? $definition : $definition->withBaseline((string) $first);
    }

    /**
     * A bound typed beyond the shortest run is stored as that run, so the field
     * shows the maximum instead of a number the answer would silently cap.
     * Costs one evaluation.
     */
    public function withinShortestRun(User $viewer, QuestionDefinition $definition): QuestionDefinition
    {
        $shortest = $this->evaluator->evaluate($viewer, $definition)->shortestRun;
        $turns = $definition->turns;

        if ($shortest === null || ($turns->from <= $shortest && ($turns->to ?? 0) <= $shortest)) {
            return $definition;
        }

        return $definition->withTurns(new TurnRange(min($turns->from, $shortest), min($turns->to ?? $shortest, $shortest)));
    }

    /**
     * Tag values deleted since the question was saved have no checkbox, so the
     * user could never remove them: they are dropped. TagFilter::without()
     * keeps a category whose values are all gone, so a filter never widens
     * silently; its chip reads "deleted value" until the user picks others.
     *
     * @return array{definition: QuestionDefinition, dropped: int} the definition unchanged when nothing was dropped
     */
    public function withoutDeletedTagValues(QuestionDefinition $definition): array
    {
        $stored = $definition->toArray();
        $filters = ['people' => TagFilter::fromArray($stored['people']['tags'] ?? []), 'projects' => TagFilter::fromArray($stored['projects']['tags'] ?? [])];
        $named = array_merge(...array_map(fn (TagFilter $filter) => $filter->valueIds(), array_values($filters)));
        $gone = $named === [] ? [] : array_values(array_diff($named, TagValue::whereKey($named)->pluck('id')->all()));

        if ($gone === []) {
            return ['definition' => $definition, 'dropped' => 0];
        }

        foreach ($filters as $part => $filter) {
            $stored[$part]['tags'] = $filter->without($gone)->toArray();
        }

        return ['definition' => QuestionDefinition::fromArray($stored), 'dropped' => count($gone)];
    }
}
