<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Metrics\Questions\QuestionNormaliser;
use App\Discussion\Metrics\Questions\QuestionSentence;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionSource;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\ResultForm;
use App\Models\Expert;
use App\Models\ProjectGroup;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One question, edited in place: its sentence on top, its answer below. Every
 * change is normalised through QuestionDefinition and QuestionNormaliser and
 * saved at once; there is no draft.
 * The user's own questions open for editing. A template opens read only:
 * nothing it shows is saved, the form and curve switches change this view and
 * nothing else, and the page copies it to adjust.
 *
 * The page renames a question through its own modal: question-renamed renders
 * the editor again so the heading follows.
 */
#[On('question-renamed')]
class QuestionEditor extends Component
{
    #[Locked]
    public int $questionId;

    /** @var array<string, mixed> QuestionDefinition::toArray() */
    public array $definition = [];

    /** Free text under the title: what the question asks, what was concluded. Saved like the question. */
    public string $notes = '';

    /** What the baseline radio group is bound to: the stored baseline, with NO_VALUE standing for the "no value" group (Flux reads '' as unset). */
    public string $baselineChoice = '';

    /** The radio value of the "no value" group; the stored baseline stays ''. */
    public const NO_VALUE = 'none';

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

        if (! $this->readOnly) {
            $this->saveEffectiveBaseline();
        }

        $this->syncBaselineChoice();
    }

    public function updatedNotes(): void
    {
        $question = $this->question($this->questionId);

        // A template's notes are only shown, so nothing is saved there.
        if ($this->readOnly) {
            $this->notes = (string) $question->notes;
            $this->skipRender();

            return;
        }

        // Trimmed; empty is no notes.
        $this->notes = trim($this->notes);
        $question->update(['notes' => $this->notes === '' ? null : $this->notes]);

        // The notes change no number: the list re-renders for its first line, the answer stays as it is.
        $this->dispatch('question-saved');
        $this->skipRender();
    }

    public function updatedBaselineChoice(): void
    {
        if ($this->readOnly) {
            $this->syncBaselineChoice();
            $this->skipRender();

            return;
        }

        $this->definition['baseline'] = $this->baselineChoice === self::NO_VALUE ? DimensionValues::NONE : $this->baselineChoice;
        $this->saveDefinition('baseline');
    }

    /** @param  string|null  $key  the changed part of the definition, e.g. 'compare_by' or 'turns.from'; null when all of it was set */
    public function updatedDefinition(mixed $value, ?string $key): void
    {
        if ($this->readOnly) {
            // Back to the stored definition, which may name tag values deleted since: drop them again.
            $this->definition = $this->onlyTheViewChanged();
            $this->dropDeletedTagValues();

            return;
        }

        $this->saveDefinition($key);
    }

    private function saveDefinition(?string $key): void
    {
        // A baseline names a value of one dimension; another dimension starts from its own first value.
        if ($key === 'compare_by') {
            $this->definition['baseline'] = null;
        }

        $definition = $this->normaliser()->withBaseline($this->user(), $this->currentDefinition());

        // Costs one evaluation per turn edit; the render evaluates again.
        if (str_starts_with((string) $key, 'turns')) {
            $definition = $this->normaliser()->withinShortestRun($this->user(), $definition);
        }

        $this->definition = $definition->toArray();
        $this->question($this->questionId)->update(['definition' => $this->definition]);
        $this->syncBaselineChoice();

        $this->dispatch('question-saved');
    }

    /** An own question shows the baseline the evaluator uses: store the fallback instead of leaving it implicit. */
    private function saveEffectiveBaseline(): void
    {
        $effective = $this->normaliser()->withBaseline($this->user(), $this->currentDefinition())->toArray();

        if ($effective !== $this->definition) {
            $this->definition = $effective;
            $this->question($this->questionId)->update(['definition' => $this->definition]);
        }
    }

    /** The radio value for the effective baseline; a template is shown with it but never saved. */
    private function syncBaselineChoice(): void
    {
        $baseline = $this->normaliser()->withBaseline($this->user(), $this->currentDefinition())->baseline;
        $this->baselineChoice = $this->radioValue($baseline);
    }

    /** The stored question with the form and curve of this view: the only things a viewer may switch. */
    private function onlyTheViewChanged(): array
    {
        $stored = $this->question($this->questionId)->definition()->toArray();
        $viewed = $this->currentDefinition()->toArray();

        return QuestionDefinition::fromArray(['form' => $viewed['form'], 'curve' => $viewed['curve']] + $stored)->toArray();
    }

    /** Tag values deleted since the question was saved are dropped, counted for the notice and, on an own question, saved. */
    private function dropDeletedTagValues(): void
    {
        ['definition' => $definition, 'dropped' => $dropped] = $this->normaliser()->withoutDeletedTagValues($this->currentDefinition());

        if ($dropped === 0) {
            return;
        }

        $this->definition = $definition->toArray();
        $this->droppedTagValues = $dropped;

        if (! $this->readOnly) {
            $this->question($this->questionId)->update(['definition' => $this->definition]);
        }
    }

    private function currentDefinition(): QuestionDefinition
    {
        return QuestionDefinition::fromArray($this->definition);
    }

    /** Flux reads '' as unset, so the "no value" group travels as NO_VALUE. */
    private function radioValue(int|string|null $baseline): string
    {
        return $baseline === DimensionValues::NONE ? self::NO_VALUE : (string) $baseline;
    }

    private function normaliser(): QuestionNormaliser
    {
        return app(QuestionNormaliser::class);
    }

    private function user(): User
    {
        return auth()->user();
    }

    private function question(int $questionId): Question
    {
        if ($this->loaded?->id !== $questionId) {
            $this->loaded = Question::visibleTo($this->user())->findOrFail($questionId);
        }

        return $this->loaded;
    }

    public function render(): mixed
    {
        $definition = $this->currentDefinition();
        $result = app(QuestionEvaluator::class)->evaluate($this->user(), $definition);

        return view('livewire.statistics.question-editor', [
            'title' => $this->question($this->questionId)->name,
            'current' => $definition,
            'mayManage' => Gate::allows('update', $this->question($this->questionId)),
            'mayShare' => Gate::allows('manageTemplates', Question::class),
            'parts' => $this->sentence()->prime([$definition])->parts($definition, $result),
            'result' => $result,
        ]);
    }

    /** @return list<Metric> */
    #[Computed]
    public function metrics(): array
    {
        return Metric::cases();
    }

    /** @return list<Aggregate> */
    #[Computed]
    public function aggregates(): array
    {
        return Aggregate::cases();
    }

    /** @return list<ResultForm> */
    #[Computed]
    public function forms(): array
    {
        return ResultForm::cases();
    }

    /** @return Collection<int, TagCategory> with their values */
    #[Computed]
    public function personaCategories(): Collection
    {
        return TagCategory::ofScope(TagScope::Persona)->with('values')->get();
    }

    /** @return Collection<int, TagCategory> with their values */
    #[Computed]
    public function projectCategories(): Collection
    {
        return TagCategory::ofScope(TagScope::Project)->with('values')->get();
    }

    /**
     * Where each menu group's values come from, named in its heading.
     *
     * @return array{persona: DimensionSource, project: DimensionSource, run: DimensionSource, group: DimensionSource}
     */
    #[Computed]
    public function sources(): array
    {
        return [
            'persona' => TagScope::Persona->source(),
            'project' => TagScope::Project->source(),
            'run' => DimensionSource::Run,
            'group' => DimensionSource::Group,
        ];
    }

    /** @return array<string, string> pipeline name => label */
    #[Computed]
    public function pipelines(): array
    {
        return app(PipelineRegistry::class)->options();
    }

    /** @return array<string, string> model key => label */
    #[Computed]
    public function models(): array
    {
        return ModelConfig::options();
    }

    /** @return Collection<int, string> the viewer's group id => name, in sidebar order */
    #[Computed]
    public function groups(): Collection
    {
        return ProjectGroup::where('user_id', $this->user()->id)->orderBy('position')->orderBy('name')->pluck('name', 'id');
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function expertNames(): Collection
    {
        return Expert::query()->distinct()->orderBy('name')->pluck('name');
    }

    #[Computed]
    public function rollingWindow(): int
    {
        return (int) config('discussion.share_window');
    }

    /** @return array<string, string> dimension key => label; agents are offered only where the metric compares them */
    #[Computed]
    public function compareOptions(): array
    {
        return $this->dimensionOptions($this->currentDefinition()->metric->isRunLevel() ? collect() : $this->personaCategories);
    }

    /**
     * The values of the compared dimension, keyed by their radio value: the
     * "no value" group as NO_VALUE, because Flux reads '' as unset.
     *
     * @return array<string, string> radio value => label; empty without a comparison
     */
    #[Computed]
    public function baselineOptions(): array
    {
        $compareBy = $this->currentDefinition()->compareBy;

        if ($compareBy === null) {
            return [];
        }

        $options = [];

        foreach (app(DimensionValues::class)->forUser($this->user())->labels($compareBy) as $key => $label) {
            $options[$this->radioValue($key)] = $label;
        }

        return $options;
    }

    /** @return array<string, string> dimension key => label, without the dimension compared by, which cannot split too */
    #[Computed]
    public function splitOptions(): array
    {
        $options = $this->dimensionOptions(collect());
        unset($options[$this->definition['compare_by'] ?? '']);

        return $options;
    }

    /** Up to MAX_SPLITS: once that many are ticked the others are disabled. */
    #[Computed]
    public function splitsFull(): bool
    {
        return count($this->definition['split_by'] ?? []) >= QuestionDefinition::MAX_SPLITS;
    }

    private function sentence(): QuestionSentence
    {
        return app(QuestionSentence::class)->forUser($this->user());
    }

    /**
     * @param  Collection<int, TagCategory>  $personaCategories  empty when agents cannot be compared
     * @return array<string, string> dimension key => its name with the source in brackets: project tags, persona tags, pipeline, model, group
     */
    private function dimensionOptions(Collection $personaCategories): array
    {
        $options = [];
        $sentence = $this->sentence();

        foreach ([[DimensionType::ProjectTag, $this->projectCategories], [DimensionType::PersonaTag, $personaCategories]] as [$type, $categories]) {
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
