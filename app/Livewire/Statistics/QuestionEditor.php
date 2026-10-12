<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionSource;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Question;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\TagFilter;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One question, edited in place: its sentence on top, its answer below. Every
 * change is normalised through Question and saved at once; there is no draft.
 * The user's own questions open for editing. A fix question opens read only:
 * nothing it shows is saved, the form and curve switches change this view and
 * nothing else, and the page copies it to adjust.
 */
class QuestionEditor extends Component
{
    /** The page renamed this question; render again so the heading follows. */
    protected $listeners = ['comparison-renamed' => '$refresh'];

    #[Locked]
    public int $comparisonId;

    /** @var array<string, mixed> Question::toArray() */
    public array $question = [];

    /** A fix question: shown, never saved. */
    #[Locked]
    public bool $readOnly = false;

    /** How many tag values the saved question named that no longer exist; dropped on opening. */
    #[Locked]
    public int $droppedTagValues = 0;

    /** The comparison of this request; not a property Livewire keeps between requests. */
    private ?Comparison $loaded = null;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $this->comparisonId = $comparison->id;
        $this->readOnly = $comparison->is_template;
        $this->question = $comparison->toQuestion()->toArray();
        $this->dropDeletedTagValues();
    }

    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'question')) {
            return;
        }

        if ($this->readOnly) {
            $this->question = $this->onlyTheViewChanged();

            return;
        }

        $this->question = Question::fromArray($this->question)->toArray();
        $this->comparison($this->comparisonId)->update(['question' => $this->question]);
    }

    /** The stored question with the form and curve of this view: the only things a viewer may switch. */
    private function onlyTheViewChanged(): array
    {
        $stored = $this->comparison($this->comparisonId)->toQuestion()->toArray();
        $viewed = Question::fromArray($this->question)->toArray();

        return Question::fromArray(['form' => $viewed['form'], 'curve' => $viewed['curve']] + $stored)->toArray();
    }

    /**
     * Tag values deleted since the question was saved have no checkbox, so the
     * user could never remove them. Drop them and say so. TagFilter::without()
     * keeps a category whose values are all gone, so a filter never widens
     * silently; its chip reads "deleted value" until the user picks others.
     */
    private function dropDeletedTagValues(): void
    {
        $filters = ['people' => TagFilter::fromArray($this->question['people']['tags'] ?? []), 'projects' => TagFilter::fromArray($this->question['projects']['tags'] ?? [])];
        $named = array_merge(...array_map(fn (TagFilter $filter) => $filter->valueIds(), array_values($filters)));
        $gone = array_values(array_diff($named, TagValue::whereKey($named)->pluck('id')->all()));

        if ($gone === []) {
            return;
        }

        foreach ($filters as $part => $filter) {
            $this->question[$part]['tags'] = $filter->without($gone)->toArray();
        }

        $this->question = Question::fromArray($this->question)->toArray();
        $this->droppedTagValues = count($gone);

        if (! $this->readOnly) {
            $this->comparison($this->comparisonId)->update(['question' => $this->question]);
        }
    }

    private function comparison(int $comparisonId): Comparison
    {
        if ($this->loaded?->id !== $comparisonId) {
            $this->loaded = Comparison::templates()->find($comparisonId)
                ?? auth()->user()->comparisons()->where('is_template', false)->findOrFail($comparisonId);
        }

        return $this->loaded;
    }

    public function render(): mixed
    {
        $question = Question::fromArray($this->question);
        $sentence = app(QuestionSentence::class);
        $result = app(QuestionEvaluator::class)->evaluate(auth()->user(), $question);

        return view('livewire.statistics.question-editor', [
            'title' => $this->comparison($this->comparisonId)->name,
            'current' => $question,
            'mayManage' => ! $this->readOnly || $this->comparison($this->comparisonId)->user_id === auth()->id(),
            'parts' => $sentence->parts($question, $result),
            'result' => $result,
            'metrics' => Metric::cases(),
            'aggregates' => Aggregate::cases(),
            'forms' => ResultForm::cases(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'projectSource' => TagScope::Project->source(),
            'personaSource' => TagScope::Persona->source(),
            'runSource' => DimensionSource::Run,
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'compareOptions' => $this->dimensionOptions($sentence, withPersona: ! $question->metric->isRunLevel()),
            'splitOptions' => $this->dimensionOptions($sentence, withPersona: false),
            'rollingWindow' => (int) config('discussion.share_window'),
        ]);
    }

    /** @return array<string, string> dimension key => its name with the source in brackets: project tags, persona tags, pipeline, model */
    private function dimensionOptions(QuestionSentence $sentence, bool $withPersona): array
    {
        $dimensions = [
            ...TagCategory::ofScope(TagScope::Project)->get()->map(fn (TagCategory $c) => new Dimension(DimensionType::ProjectTag, $c->id)),
            ...($withPersona ? TagCategory::ofScope(TagScope::Persona)->get()->map(fn (TagCategory $c) => new Dimension(DimensionType::PersonaTag, $c->id)) : []),
            new Dimension(DimensionType::Pipeline),
            new Dimension(DimensionType::Model),
        ];

        $options = [];

        foreach ($dimensions as $dimension) {
            $options[$dimension->key()] = $dimension->type->source()->named($sentence->dimension($dimension));
        }

        return $options;
    }
}
