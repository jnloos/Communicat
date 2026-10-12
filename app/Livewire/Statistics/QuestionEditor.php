<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Question;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\TagFilter;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One question, edited in place: its sentence on top, its answer below. Every
 * change is normalised through Question and saved at once; there is no draft.
 * Only the user's own questions open here; a template is copied, never edited.
 */
class QuestionEditor extends Component
{
    /** The page renamed this question; render again so the heading follows. */
    protected $listeners = ['comparison-renamed' => '$refresh'];

    #[Locked]
    public int $comparisonId;

    /** @var array<string, mixed> Question::toArray() */
    public array $question = [];

    /** How many tag values the saved question named that no longer exist; dropped on opening. */
    #[Locked]
    public int $droppedTagValues = 0;

    /** The comparison of this request; not a property Livewire keeps between requests. */
    private ?Comparison $loaded = null;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $this->comparisonId = $comparison->id;
        $this->question = $comparison->toQuestion()->toArray();
        $this->dropDeletedTagValues();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'question')) {
            $this->question = Question::fromArray($this->question)->toArray();
            $this->comparison($this->comparisonId)->update(['question' => $this->question]);
        }
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
        $this->comparison($this->comparisonId)->update(['question' => $this->question]);
    }

    private function comparison(int $comparisonId): Comparison
    {
        if ($this->loaded?->id !== $comparisonId) {
            $this->loaded = auth()->user()->comparisons()->where('is_template', false)->findOrFail($comparisonId);
        }

        return $this->loaded;
    }

    public function render(): mixed
    {
        $question = Question::fromArray($this->question);
        $sentence = app(QuestionSentence::class);
        $user = auth()->user();

        return view('livewire.statistics.question-editor', [
            'name' => $this->comparison($this->comparisonId)->name ?? $sentence->of($question),
            'current' => $question,
            'parts' => $sentence->parts($question),
            'result' => app(QuestionEvaluator::class)->evaluate($user, $question),
            'metrics' => Metric::cases(),
            'aggregates' => Aggregate::cases(),
            'forms' => ResultForm::cases(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'turnBudgets' => Project::query()->accessibleBy($user)->whereNotNull('turn_budget')->distinct()->orderBy('turn_budget')->pluck('turn_budget'),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'compareOptions' => $this->dimensionOptions($sentence, withPersona: ! $question->metric->isRunLevel()),
            'splitOptions' => $this->dimensionOptions($sentence, withPersona: false),
            'rollingWindow' => (int) config('discussion.share_window'),
        ]);
    }

    /** @return array<string, string> dimension key => label: project tags, persona tags, pipeline, model */
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
            $options[$dimension->key()] = $sentence->dimension($dimension);
        }

        return $options;
    }
}
