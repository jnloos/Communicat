<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionSource;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\TagFilter;
use App\Models\Expert;
use App\Models\ProjectGroup;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One question, edited in place: its sentence on top, its answer below. Every
 * change is normalised through QuestionDefinition and saved at once; there is no draft.
 * The user's own questions open for editing. A template opens read only:
 * nothing it shows is saved, the form and curve switches change this view and
 * nothing else, and the page copies it to adjust.
 */
class QuestionEditor extends Component
{
    /** The page renamed this question; render again so the heading follows. */
    protected $listeners = ['question-renamed' => '$refresh'];

    #[Locked]
    public int $questionId;

    /** @var array<string, mixed> QuestionDefinition::toArray() */
    public array $definition = [];

    /** Free text under the title: what the question asks, what was concluded. Saved like the question. */
    public string $notes = '';

    /** A template: shown, never saved. */
    #[Locked]
    public bool $readOnly = false;

    /** How many tag values the saved question named that no longer exist; dropped on opening. */
    #[Locked]
    public int $droppedTagValues = 0;

    /** The question of this request; not a property Livewire keeps between requests. */
    private ?Question $loaded = null;

    public function mount(int $questionId): void
    {
        $question = $this->question($questionId);
        $this->questionId = $question->id;
        $this->readOnly = $question->is_template;
        $this->definition = $question->definition()->toArray();
        $this->notes = (string) $question->notes;
        $this->dropDeletedTagValues();
    }

    public function updated(string $property): void
    {
        if ($property === 'notes') {
            $this->saveNotes();

            return;
        }

        if (! str_starts_with($property, 'definition')) {
            return;
        }

        if ($this->readOnly) {
            // Back to the stored definition, which may name tag values deleted since: drop them again.
            $this->definition = $this->onlyTheViewChanged();
            $this->dropDeletedTagValues();

            return;
        }

        // A baseline names a value of one dimension; another dimension starts from its own first value.
        if ($property === 'definition.compare_by') {
            $this->definition['baseline'] = null;
        }

        $this->definition = $this->withBaseline(QuestionDefinition::fromArray($this->definition))->toArray();
        $this->question($this->questionId)->update(['definition' => $this->definition]);

        $this->dispatch('question-saved');
    }

    /**
     * A compared question without a baseline gets the first value of its
     * dimension, so the sign of its delta is stored, not left to the order in
     * which the values happen to be listed later.
     */
    private function withBaseline(QuestionDefinition $definition): QuestionDefinition
    {
        if ($definition->compareBy === null || $definition->baseline !== null) {
            return $definition;
        }

        $first = array_key_first($this->dimensionValues()->labels($definition->compareBy));

        return $first === null ? $definition : $definition->withBaseline((string) $first);
    }

    private function dimensionValues(): DimensionValues
    {
        return app(DimensionValues::class)->forUser(auth()->user());
    }

    /** Trimmed; empty is no notes. A template's notes are only shown, so nothing is saved there. */
    private function saveNotes(): void
    {
        $question = $this->question($this->questionId);
        $this->notes = $this->readOnly ? (string) $question->notes : trim($this->notes);

        if ($this->readOnly) {
            $this->skipRender();

            return;
        }

        $question->update(['notes' => $this->notes === '' ? null : $this->notes]);

        // The notes change no number: the list re-renders for its first line, the answer stays as it is.
        $this->dispatch('question-saved');
        $this->skipRender();
    }

    /** The stored question with the form and curve of this view: the only things a viewer may switch. */
    private function onlyTheViewChanged(): array
    {
        $stored = $this->question($this->questionId)->definition()->toArray();
        $viewed = QuestionDefinition::fromArray($this->definition)->toArray();

        return QuestionDefinition::fromArray(['form' => $viewed['form'], 'curve' => $viewed['curve']] + $stored)->toArray();
    }

    /**
     * Tag values deleted since the question was saved have no checkbox, so the
     * user could never remove them. Drop them and say so. TagFilter::without()
     * keeps a category whose values are all gone, so a filter never widens
     * silently; its chip reads "deleted value" until the user picks others.
     */
    private function dropDeletedTagValues(): void
    {
        $filters = ['people' => TagFilter::fromArray($this->definition['people']['tags'] ?? []), 'projects' => TagFilter::fromArray($this->definition['projects']['tags'] ?? [])];
        $named = array_merge(...array_map(fn (TagFilter $filter) => $filter->valueIds(), array_values($filters)));

        if ($named === []) {
            return;
        }

        $gone = array_values(array_diff($named, TagValue::whereKey($named)->pluck('id')->all()));

        if ($gone === []) {
            return;
        }

        foreach ($filters as $part => $filter) {
            $this->definition[$part]['tags'] = $filter->without($gone)->toArray();
        }

        $this->definition = QuestionDefinition::fromArray($this->definition)->toArray();
        $this->droppedTagValues = count($gone);

        if (! $this->readOnly) {
            $this->question($this->questionId)->update(['definition' => $this->definition]);
        }
    }

    private function question(int $questionId): Question
    {
        if ($this->loaded?->id !== $questionId) {
            $this->loaded = Question::templates()->find($questionId)
                ?? auth()->user()->questions()->notTemplates()->findOrFail($questionId);
        }

        return $this->loaded;
    }

    public function render(): mixed
    {
        $definition = QuestionDefinition::fromArray($this->definition);
        $sentence = app(QuestionSentence::class)->prime([$definition]);
        $result = app(QuestionEvaluator::class)->evaluate(auth()->user(), $definition);
        $personaCategories = TagCategory::ofScope(TagScope::Persona)->with('values')->get();
        $projectCategories = TagCategory::ofScope(TagScope::Project)->with('values')->get();

        return view('livewire.statistics.question-editor', [
            'title' => $this->question($this->questionId)->name,
            'current' => $definition,
            'mayManage' => $this->mayManage(),
            'mayShare' => Gate::allows('admin'),
            'parts' => $sentence->parts($definition, $result),
            'result' => $result,
            'metrics' => Metric::cases(),
            'aggregates' => Aggregate::cases(),
            'forms' => ResultForm::cases(),
            'personaCategories' => $personaCategories,
            'projectCategories' => $projectCategories,
            'projectSource' => TagScope::Project->source(),
            'personaSource' => TagScope::Persona->source(),
            'runSource' => DimensionSource::Run,
            'groupSource' => DimensionSource::Group,
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'groups' => ProjectGroup::where('user_id', auth()->id())->orderBy('position')->orderBy('name')->pluck('name', 'id'),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'compareOptions' => $this->dimensionOptions($sentence, $projectCategories, $definition->metric->isRunLevel() ? collect() : $personaCategories),
            'baselineOptions' => $definition->compareBy === null ? [] : $this->dimensionValues()->labels($definition->compareBy),
            'splitOptions' => $this->dimensionOptions($sentence, $projectCategories, collect()),
            'rollingWindow' => (int) config('discussion.share_window'),
        ]);
    }

    /**
     * An own question is managed by its owner; a template only by an admin who owns it.
     */
    private function mayManage(): bool
    {
        return ! $this->readOnly
            || ($this->question($this->questionId)->user_id === auth()->id() && Gate::allows('admin'));
    }

    /**
     * @param  Collection<int, TagCategory>  $projectCategories
     * @param  Collection<int, TagCategory>  $personaCategories  empty when agents cannot be compared
     * @return array<string, string> dimension key => its name with the source in brackets: project tags, persona tags, pipeline, model, group
     */
    private function dimensionOptions(QuestionSentence $sentence, Collection $projectCategories, Collection $personaCategories): array
    {
        $options = [];

        foreach ([[DimensionType::ProjectTag, $projectCategories], [DimensionType::PersonaTag, $personaCategories]] as [$type, $categories]) {
            foreach ($categories as $category) {
                $options[(new Dimension($type, $category->id))->key()] = $type->source()->named($category->label());
            }
        }

        foreach ([DimensionType::Pipeline, DimensionType::Model, DimensionType::Group] as $type) {
            $dimension = new Dimension($type);
            $options[$dimension->key()] = $type->source()->named($sentence->dimension($dimension));
        }

        return $options;
    }
}
