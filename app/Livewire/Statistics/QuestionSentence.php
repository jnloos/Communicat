<?php

namespace App\Livewire\Statistics;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\PeopleFilter;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\Question;
use App\Models\TagCategory;
use App\Models\TagValue;

/**
 * A question in words: part by part for the chips of the editor, joined for
 * the name of a question nobody named. Reads labels; decides nothing.
 */
final class QuestionSentence
{
    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /** @return array{metric: string, people: ?string, aggregate: ?string, projects: string, compare_by: ?string, split_by: ?string} */
    public function parts(Question $question): array
    {
        return [
            'metric' => $question->metric->label(),
            'people' => $question->metric->isRunLevel() ? null : $this->people($question->people),
            'aggregate' => $question->metric->isShare() ? $question->aggregate->label() : null,
            'projects' => $this->projects($question->projects),
            'compare_by' => $question->compareBy === null ? null : $this->dimension($question->compareBy),
            'split_by' => $question->splitBy === null ? null : $this->dimension($question->splitBy),
        ];
    }

    public function of(Question $question): string
    {
        $parts = $this->parts($question);

        return implode(' · ', array_filter([
            $parts['metric'],
            $parts['people'],
            $question->projects->isEmpty() ? null : $parts['projects'],
            $parts['compare_by'] === null ? null : __('questions.sentence.name_compared_by', ['dimension' => $parts['compare_by']]),
            $parts['split_by'] === null ? null : __('questions.sentence.name_split_by', ['dimension' => $parts['split_by']]),
        ]));
    }

    public function dimension(Dimension $dimension): string
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => __('questions.dimensions.pipeline'),
            DimensionType::Model => __('questions.dimensions.model'),
            DimensionType::ProjectTag => $this->category($dimension->categoryId),
            DimensionType::PersonaTag => __('questions.dimensions.persona', ['category' => $this->category($dimension->categoryId)]),
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
            ...($projects->turnBudget === null ? [] : [trans_choice('questions.sentence.turn_budget', $projects->turnBudget, ['count' => $projects->turnBudget])]),
        ]);
    }

    /**
     * @param  list<int>  $valueIds
     * @return list<string>
     */
    private function tags(array $valueIds): array
    {
        return TagValue::whereKey($valueIds)->with('category')->orderBy('tag_category_id')->orderBy('position')->get()
            ->map(fn (TagValue $value) => $value->fullLabel())
            ->all();
    }

    private function category(?int $id): string
    {
        return TagCategory::find($id)?->label() ?? __('questions.sentence.deleted');
    }
}
