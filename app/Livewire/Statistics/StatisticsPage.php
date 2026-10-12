<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Question;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Comparison;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: everyone's fix questions and the user's other questions
 * on the left, the open question on the right. An own question is always
 * resolved through the user's own rows; someone else's does not exist here.
 * A fix question (the rows with is_template) is opened by everyone to view,
 * copied to adjust, renamed or deleted only by its owner.
 */
class StatisticsPage extends Component
{
    use NeedsConfirmation;

    #[Url(as: 'c')]
    public ?int $comparisonId = null;

    #[Locked]
    public ?int $renamingId = null;

    public string $name = '';

    /** Why a saved template differs from its question; shown until the user opens something else. */
    #[Locked]
    public ?string $templateNotice = null;

    public function mount(): void
    {
        if ($this->comparisonId !== null && $this->openable($this->comparisonId) === null) {
            $this->comparisonId = null;
        }
    }

    public function open(int $comparisonId): void
    {
        $this->templateNotice = null;
        $this->comparisonId = ($this->openable($comparisonId) ?? abort(404))->id;
    }

    /** An own question or any fix question; null for someone else's question. */
    private function openable(int $comparisonId): ?Comparison
    {
        return $this->user()->comparisons()->questions()->find($comparisonId)
            ?? Comparison::templates()->find($comparisonId);
    }

    public function create(): void
    {
        $this->comparisonId = $this->user()->comparisons()->create(['question' => (new Question)->toArray()])->id;
    }

    /**
     * A fix question becomes the user's own question, to adjust; the fix
     * question itself stays as it is. The form being viewed may come along.
     */
    public function copyFixQuestion(int $fixQuestionId, ?string $form = null, ?string $curve = null): void
    {
        $fix = Comparison::templates()->findOrFail($fixQuestionId);
        $question = $fix->question ?? [];

        if ($form !== null) {
            $question['form'] = $form;
        }

        if ($curve !== null) {
            $question['curve'] = $curve;
        }

        $this->comparisonId = $this->user()->comparisons()->create([
            'name' => $fix->name,
            'notes' => $fix->notes,
            'question' => Question::fromArray($question)->toArray(),
        ])->id;
    }

    /** A snapshot of the question as a fix question (without groups, which are personal), titled like it, or by its sentence when untitled; the question stays the user's own. */
    public function saveAsTemplate(int $comparisonId): void
    {
        $question = $this->owned($comparisonId);
        $asked = $question->toQuestion();
        $template = $asked->withoutGroups();

        $this->user()->comparisons()->create([
            'name' => Str::limit($question->name ?? app(QuestionSentence::class)->of($asked), 100, ''),
            'notes' => $question->notes,
            'question' => $template->toArray(),
            'is_template' => true,
        ]);

        // Groups are drawers in one person's sidebar: for anyone else the template would match nothing.
        $this->templateNotice = $template == $asked ? null : __('questions.fix.without_groups');
    }

    public function startRenaming(int $comparisonId): void
    {
        $comparison = $this->owned($comparisonId);
        $this->renamingId = $comparison->id;
        $this->name = (string) $comparison->name;
        $this->resetValidation();
        Flux::modal('question-name')->show();
    }

    /**
     * An empty title is allowed for a question: it is then listed as untitled
     * (its sentence is always shown beside the title). A fix question is read
     * by everyone and keeps a title.
     */
    public function saveName(): void
    {
        $comparison = $this->owned((int) $this->renamingId);
        $this->name = trim($this->name);
        $this->validate(['name' => [$comparison->is_template ? 'required' : 'nullable', 'string', 'max:100']], [], ['name' => __('questions.name.field')]);

        $comparison->update(['name' => $this->name === '' ? null : $this->name]);
        $this->dispatch('comparison-renamed');

        $this->reset(['renamingId', 'name']);
        Flux::modal('question-name')->close();
    }

    public function duplicate(int $comparisonId): void
    {
        $source = $this->user()->comparisons()->questions()->findOrFail($comparisonId);
        $copy = $source->replicate();
        $copy->name = $source->name === null ? null : Str::limit(__('questions.copy_name', ['name' => $source->name]), 100, '');
        $copy->save();

        $this->comparisonId = $copy->id;
    }

    public function confirmDelete(int $comparisonId): void
    {
        $comparison = $this->owned($comparisonId);
        $kind = $comparison->is_template ? 'delete_template' : 'delete';

        $this->confirmTitle = __("questions.confirm.{$kind}.title");
        $this->confirmMessage = __("questions.confirm.{$kind}.message", ['name' => $comparison->name ?? __('questions.untitled')]);
        $this->needsConfirmation('delete', $comparisonId);
    }

    public function delete(int $comparisonId): void
    {
        $this->owned($comparisonId)->delete();

        if ($this->comparisonId === $comparisonId) {
            $this->comparisonId = null;
        }
    }

    /** One of the user's own rows, question or fix question. */
    private function owned(int $comparisonId): Comparison
    {
        return $this->user()->comparisons()->findOrFail($comparisonId);
    }

    private function user(): User
    {
        return auth()->user();
    }

    /** The editor saved a change: re-render so the list shows the new sentence. */
    #[On('question-saved')]
    public function refreshList(): void {}

    public function render(): mixed
    {
        $questions = $this->user()->comparisons()->questions()->get();

        $fixQuestions = Comparison::templates()->get();

        return view('livewire.statistics.statistics-page', [
            'questions' => $questions,
            'fixQuestions' => $fixQuestions,
            'sentences' => $questions->concat($fixQuestions)->mapWithKeys(fn (Comparison $comparison) => [$comparison->id => app(QuestionSentence::class)->of($comparison->toQuestion())]),
            'current' => $this->comparisonId === null ? null : $questions->firstWhere('id', $this->comparisonId) ?? $fixQuestions->firstWhere('id', $this->comparisonId),
        ])->title(__('questions.heading'));
    }
}
