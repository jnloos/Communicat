<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\SeriesDefinition;
use App\Livewire\Concerns\NeedsConfirmation;
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
    use NeedsConfirmation;

    /** The page renamed this comparison; render again so the heading follows. */
    protected $listeners = ['comparison-renamed' => '$refresh'];

    #[Locked]
    public int $comparisonId;

    /** @var list<array<string, mixed>> SeriesDefinition::toArray() per series */
    public array $series = [];

    /** @var array<string, mixed> ComparisonView::toArray() */
    public array $viewSettings = [];

    /** The series added last in this session: its card opens even when the others start folded. */
    #[Locked]
    public ?int $addedSeries = null;

    /** Tag values this comparison named that have since been deleted. */
    #[Locked]
    public int $droppedTagValues = 0;

    /** The comparison of this request; not a property Livewire keeps between requests. */
    private ?Comparison $loaded = null;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $definitions = $comparison->seriesDefinitions();

        // Ids only: a template's unknown tag names are no deletion.
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
        $this->series[] = (new SeriesDefinition(SeriesLabels::next($this->definitions())))->toArray();
        $this->addedSeries = array_key_last($this->series);
        $this->persist();
    }

    public function confirmRemoveSeries(int $index): void
    {
        $definition = SeriesLabels::withDefaults($this->definitions())[$index] ?? null;

        if ($definition === null) {
            return;
        }

        $this->confirmTitle = __('comparisons.confirm.remove_series.title');
        $this->confirmMessage = __('comparisons.confirm.remove_series.message', ['label' => $definition->label]);
        $this->needsConfirmation('removeSeries', $index);
    }

    public function removeSeries(int $index): void
    {
        unset($this->series[$index]);
        $this->addedSeries = null;
        $this->persist();
    }

    /** Removes one tag name the series' template knew and this database does not. */
    public function removeUnresolvedTag(int $index, int $tag): void
    {
        $definition = $this->definitions()[$index] ?? null;

        if ($definition === null) {
            return;
        }

        $this->series[$index] = $definition->withoutUnresolvedTag($tag)->toArray();
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
        return SeriesDefinition::listFrom($this->series);
    }

    /**
     * Through the user's own comparisons: someone else's does not exist here.
     * Loaded once per request, so saving and rendering share one query.
     */
    private function comparison(int $comparisonId): Comparison
    {
        if ($this->loaded?->id !== $comparisonId) {
            $this->loaded = auth()->user()->comparisons()->findOrFail($comparisonId);
        }

        return $this->loaded;
    }

    public function render(): mixed
    {
        $definitions = $this->definitions();
        $pipelines = app(PipelineRegistry::class)->options();
        $models = ModelConfig::options();
        $result = ComparisonResult::compute(auth()->user(), $definitions, ComparisonView::fromArray($this->viewSettings), $pipelines, $models);

        return view('livewire.statistics.comparison-editor', [
            'name' => $this->comparison($this->comparisonId)->name,
            'definitions' => $definitions,
            'emptiedSeries' => $result->emptiedSeries,
            'unresolvedSeries' => $result->unresolvedSeries,
            'matches' => $result->matches,
            'results' => $result->results,
            'lines' => $result->lines,
            'seriesColors' => $result->colors,
            'summaries' => $result->summaries,
            'openCards' => $this->openCards(count($definitions)),
            ...$result->chartData(),
            'paletteRepeats' => $result->paletteRepeats(),
            'rollingWindow' => (int) config('discussion.share_window'),
            'comparisonView' => $result->view,
            'metrics' => Metric::cases(),
            'displays' => ComparisonDisplay::cases(),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'pipelines' => $pipelines,
            'models' => $models,
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'referenceTypes' => array_keys(AgentReferences::TYPES),
        ]);
    }

    /**
     * Cards start open while a comparison has up to three series; beyond
     * that they start folded to their summary, so the result stays near the
     * top. The one just added opens. Folding is client state, never saved.
     *
     * @return list<bool>
     */
    private function openCards(int $count): array
    {
        return array_map(fn (int $index) => $count <= 3 || $index === $this->addedSeries, $count === 0 ? [] : range(0, $count - 1));
    }
}
