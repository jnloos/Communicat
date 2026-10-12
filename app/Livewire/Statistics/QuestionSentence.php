<?php

namespace App\Livewire\Statistics;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\PeopleFilter;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\Question;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Models\TagCategory;
use App\Models\TagValue;

/**
 * A question in words: part by part for the chips of the editor, joined into
 * the sentence shown beside every question's title. Reads labels; decides nothing.
 */
final class QuestionSentence
{
    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /**
     * @param  QuestionResult|null  $result  what the question was read over; its window carries the upper bound the
     *                                       shortest run set. Without it the window reads as asked.
     * @return array{metric: string, people: ?string, aggregate: ?string, projects: string, turns: string, compare_by: ?string, split_by: ?string}
     */
    public function parts(Question $question, ?QuestionResult $result = null): array
    {
        return [
            'metric' => $question->metric->label(),
            'people' => $question->metric->isRunLevel() ? null : $this->people($question->people),
            'aggregate' => $question->metric->isShare() ? $question->aggregate->label() : null,
            'projects' => $this->projects($question->projects),
            'turns' => $this->turns($result?->turns ?? $question->turns, $result),
            'compare_by' => $question->compareBy === null ? null : $this->dimension($question->compareBy),
            'split_by' => $question->splitBy === null ? null : $this->dimension($question->splitBy),
        ];
    }

    public function of(Question $question): string
    {
        $parts = $this->parts($question);

        $main = implode(' ', array_filter([
            $parts['metric'],
            $parts['people'] === null ? null : __('questions.sentence.of_people', ['people' => $parts['people']]),
            $question->metric->isShare() && $question->aggregate === Aggregate::PerHead ? $parts['aggregate'] : null,
            $question->projects->isEmpty() ? null : __('questions.sentence.in_projects', ['projects' => $parts['projects']]),
            // The window as asked, not as capped: the name must not change when runs are added.
            $question->turns->isWholeRun() ? null : $parts['turns'],
        ]));

        return implode(', ', array_filter([
            $main,
            $parts['compare_by'] === null ? null : __('questions.sentence.compared_by_dimension', ['dimension' => $parts['compare_by']]),
            $parts['split_by'] === null ? null : __('questions.sentence.split_by_dimension', ['dimension' => $parts['split_by']]),
        ]));
    }

    public function dimension(Dimension $dimension): string
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => __('questions.dimensions.pipeline'),
            DimensionType::Model => __('questions.dimensions.model'),
            DimensionType::ProjectTag, DimensionType::PersonaTag => $this->category($dimension->categoryId),
        };
    }

    private function people(PeopleFilter $people): string
    {
        if ($people->isEveryone()) {
            return __('questions.sentence.everyone');
        }

        return implode(', ', [...$this->tags($people->tags->valueIds()), ...array_filter([$people->expertName])]);
    }

    private function projects(ProjectFilter $projects): string
    {
        if ($projects->isEmpty()) {
            return __('questions.sentence.all_runs');
        }

        $models = ModelConfig::options();

        return implode(', ', [
            ...$this->tags($projects->tags->valueIds()),
            ...array_map(fn (string $name) => $this->pipelines->label($name), $projects->pipelines),
            ...array_map(fn (string $key) => $models[$key] ?? $key, $projects->models),
        ]);
    }

    private function turns(TurnRange $turns, ?QuestionResult $result): string
    {
        // A start past the shortest run: no run has such a turn, so say why the answer is empty.
        if ($result?->shortestRun !== null && $turns->from > $result->shortestRun) {
            return __('questions.sentence.beyond_shortest_run', ['from' => $turns->from, 'count' => $result->shortestRun]);
        }

        return match (true) {
            $turns->isWholeRun() => __('questions.sentence.all_turns'),
            $turns->to === null => __('questions.sentence.from_turn', ['from' => $turns->from]),
            default => __('questions.sentence.turns', ['from' => $turns->from, 'to' => $turns->to]),
        };
    }

    /**
     * @param  list<int>  $valueIds
     * @return list<string>
     */
    private function tags(array $valueIds): array
    {
        $values = TagValue::whereKey($valueIds)->with('category')->orderBy('tag_category_id')->orderBy('position')->get();
        $deleted = array_fill(0, count($valueIds) - $values->count(), __('questions.sentence.deleted_value'));

        // A chip is never empty: a value that was deleted still says so, and stays until the user removes it.
        return [...$values->map(fn (TagValue $value) => $value->fullLabel())->all(), ...$deleted];
    }

    private function category(?int $id): string
    {
        return TagCategory::find($id)?->label() ?? __('questions.sentence.deleted');
    }
}
