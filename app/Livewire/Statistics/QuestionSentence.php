<?php

namespace App\Livewire\Statistics;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\PeopleFilter;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagValue;

/**
 * A question in words: part by part for the chips of the editor, joined into
 * the sentence shown beside every question's title. Reads labels; decides nothing.
 *
 * The tag values, categories and groups it names are read once per instance
 * and kept; prime() reads those of many questions in one go, so a list of
 * sentences costs a few queries, not a few per entry.
 */
final class QuestionSentence
{
    /** @var array<int, TagValue> id => value with its category; an id read but not found is absent */
    private array $values = [];

    /** @var array<int, ?TagCategory> id => category, null for one that no longer exists */
    private array $categories = [];

    /** @var array<int, ?ProjectGroup> id => the viewer's group, null for one that is not (or no longer) theirs */
    private array $groupsRead = [];

    /** @var array<int, true> tag value ids already asked for */
    private array $valuesRead = [];

    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /**
     * Reads ahead what these questions name, in at most three queries.
     *
     * @param  iterable<QuestionDefinition>  $definitions
     */
    public function prime(iterable $definitions): self
    {
        $valueIds = $categoryIds = $groupIds = [];

        foreach ($definitions as $definition) {
            array_push($valueIds, ...$definition->people->tags->valueIds(), ...$definition->projects->tags->valueIds());
            array_push($groupIds, ...$definition->projects->groups);

            foreach ([$definition->compareBy, ...$definition->splitBy] as $dimension) {
                if ($dimension?->categoryId !== null) {
                    $categoryIds[] = $dimension->categoryId;
                }
            }
        }

        $this->readValues($valueIds);
        $this->readCategories($categoryIds);
        $this->readGroups($groupIds);

        return $this;
    }

    /**
     * @param  QuestionResult|null  $result  what the question was read over; its window carries the upper bound the
     *                                       shortest run set. Without it the window reads as asked.
     * @return array{metric: string, people: ?string, aggregate: ?string, projects: string, turns: string, compare_by: ?string, split_by: ?string}
     */
    public function parts(QuestionDefinition $question, ?QuestionResult $result = null): array
    {
        return [
            'metric' => $question->metric->label(),
            'people' => $question->metric->isRunLevel() ? null : $this->people($question->people),
            'aggregate' => $question->metric->isShare() ? $question->aggregate->label() : null,
            'projects' => $this->projects($question->projects),
            'turns' => $this->turns($result?->turns ?? $question->turns, $result),
            'compare_by' => $question->compareBy === null ? null : $this->dimension($question->compareBy),
            'split_by' => $question->splitBy === [] ? null : $this->dimensions($question->splitBy),
        ];
    }

    public function of(QuestionDefinition $question): string
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
            DimensionType::Group => __('questions.dimensions.group'),
            DimensionType::ProjectTag, DimensionType::PersonaTag => $this->category($dimension->categoryId),
        };
    }

    /** @param  non-empty-list<Dimension>  $dimensions */
    private function dimensions(array $dimensions): string
    {
        return implode(' '.__('questions.sentence.and').' ', array_map(fn (Dimension $dimension) => $this->dimension($dimension), $dimensions));
    }

    private function people(PeopleFilter $people): string
    {
        if ($people->isEveryone()) {
            return __('questions.sentence.everyone');
        }

        return implode(', ', [...$this->tags($people->tags->valueIds()), ...$people->expertNames]);
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
            ...$this->groups($projects->groups),
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
        $this->readValues($valueIds);
        $values = array_values(array_intersect_key($this->values, array_flip($valueIds)));
        usort($values, fn (TagValue $a, TagValue $b) => [$a->tag_category_id, $a->position, $a->id] <=> [$b->tag_category_id, $b->position, $b->id]);
        $deleted = array_fill(0, count(array_unique($valueIds)) - count($values), __('questions.sentence.deleted_value'));

        // A chip is never empty: a value that was deleted still says so, and stays until the user removes it.
        return [...array_map(fn (TagValue $value) => $value->fullLabel(), $values), ...$deleted];
    }

    /** @param  list<int>  $ids */
    private function readValues(array $ids): void
    {
        $unread = array_values(array_unique(array_diff($ids, array_keys($this->valuesRead))));

        if ($unread === []) {
            return;
        }

        foreach (TagValue::whereKey($unread)->with('category')->get() as $value) {
            $this->values[$value->id] = $value;
            $this->categories[$value->tag_category_id] = $value->category;
        }

        $this->valuesRead += array_fill_keys($unread, true);
    }

    /** @param  list<int>  $ids */
    private function readCategories(array $ids): void
    {
        $unread = array_values(array_unique(array_diff($ids, array_keys($this->categories))));

        if ($unread === []) {
            return;
        }

        $found = TagCategory::whereKey($unread)->get()->keyBy('id');

        foreach ($unread as $id) {
            $this->categories[$id] = $found[$id] ?? null;
        }
    }

    /** @param  list<int>  $ids */
    private function readGroups(array $ids): void
    {
        $unread = array_values(array_unique(array_diff($ids, array_keys($this->groupsRead))));

        if ($unread === []) {
            return;
        }

        $found = ProjectGroup::where('user_id', auth()->id())->whereKey($unread)->get()->keyBy('id');

        foreach ($unread as $id) {
            $this->groupsRead[$id] = $found[$id] ?? null;
        }
    }

    /**
     * The viewing user's own groups, in sidebar order. An id that is no longer
     * theirs says so, and the filter keeps matching nothing for it.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function groups(array $ids): array
    {
        $this->readGroups($ids);
        $groups = array_values(array_filter(array_map(fn (int $id) => $this->groupsRead[$id] ?? null, array_unique($ids))));
        usort($groups, fn (ProjectGroup $a, ProjectGroup $b) => [$a->position, $a->name] <=> [$b->position, $b->name]);

        return [
            ...array_map(fn (ProjectGroup $group) => $group->name, $groups),
            ...array_fill(0, count(array_unique($ids)) - count($groups), __('questions.sentence.deleted_group')),
        ];
    }

    private function category(?int $id): string
    {
        if ($id === null) {
            return __('questions.sentence.deleted');
        }

        $this->readCategories([$id]);

        return $this->categories[$id]?->label() ?? __('questions.sentence.deleted');
    }
}
