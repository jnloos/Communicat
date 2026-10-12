<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Taggable;
use App\Models\TagValue;
use LogicException;

/**
 * The values of a dimension: their labels in display order, and the value a
 * run or an agent has. Keys are tag value ids, pipeline names or model keys.
 * An agent without a value in a persona category gets NONE, so the groups of
 * a run still add up to everyone.
 */
final class DimensionValues
{
    public const NONE = '';

    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /** @return array<int|string, string> key => label */
    public function labels(Dimension $dimension): array
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => $this->pipelines->options(),
            DimensionType::Model => ModelConfig::options(),
            DimensionType::ProjectTag => $this->tagLabels($dimension),
            DimensionType::PersonaTag => $this->tagLabels($dimension) + [self::NONE => __('questions.result.no_value')],
        };
    }

    /** Null when the run has no value in a project tag category. */
    public function ofProject(Dimension $dimension, Project $project): int|string|null
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => $project->pipeline,
            DimensionType::Model => $project->model,
            DimensionType::ProjectTag => $this->tagValuesOf($dimension, $project->getMorphClass(), [$project->id])[$project->id] ?? null,
            DimensionType::PersonaTag => throw new LogicException('A persona tag describes agents, not runs.'),
        };
    }

    /**
     * @param  list<int>  $expertIds
     * @return array<int, int|string> expert id => value id, or NONE
     */
    public function ofExperts(Dimension $dimension, array $expertIds): array
    {
        if (! $dimension->isPersonaLevel()) {
            throw new LogicException('Only a persona tag describes agents.');
        }

        $values = $this->tagValuesOf($dimension, (new Expert)->getMorphClass(), $expertIds);

        return array_combine($expertIds, array_map(fn (int $id) => $values[$id] ?? self::NONE, $expertIds));
    }

    /** @return array<int, string> */
    private function tagLabels(Dimension $dimension): array
    {
        return TagValue::where('tag_category_id', $dimension->categoryId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> model id => value id
     */
    private function tagValuesOf(Dimension $dimension, string $morphClass, array $ids): array
    {
        return Taggable::where('taggable_type', $morphClass)
            ->whereIn('taggable_id', $ids)
            ->where('tag_category_id', $dimension->categoryId)
            ->pluck('tag_value_id', 'taggable_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
