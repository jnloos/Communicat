<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The values of a dimension: their labels in display order, and the value a
 * run or an agent has. Keys are tag value ids, pipeline names, model keys or group ids.
 * An agent without a value in a persona category gets NONE, so the groups of
 * a run still add up to everyone.
 */
final class DimensionValues
{
    public const NONE = '';

    /** @var array<int, int>|null project id => the user's group id; null while no user is set */
    private ?array $filing = null;

    private ?User $user = null;

    public function __construct(private readonly PipelineRegistry $pipelines) {}

    /**
     * The same values read for one user: a group is a drawer in that person's
     * sidebar, so its labels and the runs filed in it exist for them alone.
     * Their filing is read once here, not once per run.
     */
    public function forUser(User $user): self
    {
        $scoped = clone $this;
        $scoped->user = $user;
        $scoped->filing = DB::table('project_contributors')
            ->where('contributor_type', $user->getMorphClass())
            ->where('contributor_id', $user->id)
            ->whereNotNull('project_group_id')
            ->pluck('project_group_id', 'project_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $scoped;
    }

    /** @return array<int|string, string> key => label */
    public function labels(Dimension $dimension): array
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => $this->pipelines->options(),
            DimensionType::Model => ModelConfig::options(),
            DimensionType::Group => $this->user?->projectGroups()->pluck('name', 'id')->all() ?? [],
            DimensionType::ProjectTag => $this->tagLabels($dimension),
            DimensionType::PersonaTag => $this->tagLabels($dimension) + [self::NONE => $this->category($dimension).': '.__('questions.result.no_value')],
        };
    }

    /** Null when the run has no value: no project tag in the category, or no group of this user. */
    public function ofProject(Dimension $dimension, Project $project): int|string|null
    {
        return match ($dimension->type) {
            DimensionType::Pipeline => $project->pipeline,
            DimensionType::Model => $project->model,
            DimensionType::Group => $this->filing[$project->id] ?? null,
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

    /**
     * A group of a tag dimension reads "Category: value", as a tag does everywhere else.
     *
     * @return array<int, string>
     */
    private function tagLabels(Dimension $dimension): array
    {
        $category = $this->category($dimension);

        return TagValue::where('tag_category_id', $dimension->categoryId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->map(fn (string $name) => "{$category}: {$name}")
            ->all();
    }

    private function category(Dimension $dimension): string
    {
        return TagCategory::find($dimension->categoryId)?->label() ?? __('questions.sentence.deleted');
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
