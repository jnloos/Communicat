<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\SeriesDefinition;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One comparison, edited in place: its series (project filter + reference),
 * the view that applies to all of them, and — below — the result. Every edit
 * is normalised through SeriesDefinition/ComparisonView and saved at once; a
 * comparison has no draft state.
 */
class ComparisonEditor extends Component
{
    #[Locked]
    public int $comparisonId;

    /** @var list<array<string, mixed>> SeriesDefinition::toArray() per series */
    public array $series = [];

    /** @var array<string, mixed> ComparisonView::toArray() */
    public array $viewSettings = [];

    /** Tag values this comparison named that have since been deleted. */
    #[Locked]
    public int $droppedTagValues = 0;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $definitions = $comparison->seriesDefinitions();

        $named = array_values(array_unique(array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->tagValueIds(), $definitions))));
        $existing = TagValue::whereKey($named)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($named, $existing));

        $this->comparisonId = $comparison->id;
        $this->droppedTagValues = count($missing);
        $this->series = array_map(fn (SeriesDefinition $d) => $d->withoutTagValues($missing)->toArray(), $definitions);
        $this->viewSettings = $comparison->comparisonView()->toArray();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'series') || str_starts_with($property, 'viewSettings')) {
            $this->persist();
        }
    }

    public function addSeries(): void
    {
        $label = __('comparisons.series.default_label', ['n' => count($this->series) + 1]);
        $this->series[] = (new SeriesDefinition($label))->toArray();
        $this->persist();
    }

    public function removeSeries(int $index): void
    {
        unset($this->series[$index]);
        $this->persist();
    }

    /** Normalises the form state, writes it back so the form shows what is stored, and saves it. */
    private function persist(): void
    {
        $this->series = array_map(fn (SeriesDefinition $d) => $d->toArray(), $this->definitions());
        $this->viewSettings = ComparisonView::fromArray($this->viewSettings)->toArray();

        $this->comparison($this->comparisonId)->update(['series' => $this->series, 'view' => $this->viewSettings]);
    }

    /** @return list<SeriesDefinition> */
    private function definitions(): array
    {
        return array_values(array_map(
            fn (mixed $series) => SeriesDefinition::fromArray(is_array($series) ? $series : []),
            $this->series,
        ));
    }

    /** Through the user's own comparisons: someone else's does not exist here. */
    private function comparison(int $comparisonId): Comparison
    {
        return auth()->user()->comparisons()->findOrFail($comparisonId);
    }

    public function render(): mixed
    {
        $definitions = $this->definitions();

        return view('livewire.statistics.comparison-editor', [
            'definitions' => $definitions,
            'matches' => array_map(fn (SeriesDefinition $d) => app(SeriesProjects::class)->for(auth()->user(), $d), $definitions),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'referenceTypes' => array_keys(AgentReferences::TYPES),
        ]);
    }
}
