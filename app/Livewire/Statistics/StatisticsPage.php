<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Question;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Comparison;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: the user's questions and everyone's templates on the
 * left, the open question on the right. A question id is always resolved
 * through the user's own rows; someone else's question does not exist here.
 * Templates are read by everyone, copied to be used, renamed or deleted only
 * by their owner.
 */
class StatisticsPage extends Component
{
    use NeedsConfirmation;

    #[Url(as: 'c')]
    public ?int $comparisonId = null;

    #[Locked]
    public ?int $renamingId = null;

    public string $name = '';

    public function mount(): void
    {
        if ($this->comparisonId !== null && $this->user()->comparisons()->questions()->find($this->comparisonId) === null) {
            $this->comparisonId = null;
        }
    }

    public function open(int $comparisonId): void
    {
        $this->comparisonId = $this->user()->comparisons()->questions()->findOrFail($comparisonId)->id;
    }

    public function create(): void
    {
        $this->comparisonId = $this->user()->comparisons()->create(['question' => (new Question)->toArray()])->id;
    }

    /** A template becomes the user's own question; the template itself stays as it is. */
    public function useTemplate(int $templateId): void
    {
        $template = Comparison::templates()->findOrFail($templateId);

        $this->comparisonId = $this->user()->comparisons()->create([
            'name' => $template->name,
            'question' => $template->question,
        ])->id;
    }

    /** A snapshot of the question as a template, named after it; the question stays the user's own. */
    public function saveAsTemplate(int $comparisonId): void
    {
        $question = $this->owned($comparisonId);

        $this->user()->comparisons()->create([
            'name' => Str::limit($question->name ?? app(QuestionSentence::class)->of($question->toQuestion()), 100, ''),
            'question' => $question->question,
            'is_template' => true,
        ]);
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
     * An empty name is allowed for a question: it is then called by its
     * sentence again. A template has no sentence to fall back on in the list
     * everyone reads, so it keeps a name.
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
        $this->confirmMessage = __("questions.confirm.{$kind}.message", ['name' => $this->nameOf($comparison)]);
        $this->needsConfirmation('delete', $comparisonId);
    }

    public function delete(int $comparisonId): void
    {
        $this->owned($comparisonId)->delete();

        if ($this->comparisonId === $comparisonId) {
            $this->comparisonId = null;
        }
    }

    /** One of the user's own rows, question or template. */
    private function owned(int $comparisonId): Comparison
    {
        return $this->user()->comparisons()->findOrFail($comparisonId);
    }

    private function nameOf(Comparison $comparison): string
    {
        return $comparison->name ?? app(QuestionSentence::class)->of($comparison->toQuestion());
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render(): mixed
    {
        $questions = $this->user()->comparisons()->questions()->get();

        return view('livewire.statistics.statistics-page', [
            'questions' => $questions,
            'templates' => Comparison::templates()->get(),
            'names' => $questions->mapWithKeys(fn (Comparison $comparison) => [$comparison->id => $this->nameOf($comparison)]),
            'current' => $this->comparisonId === null ? null : $questions->firstWhere('id', $this->comparisonId),
        ])->title(__('questions.heading'));
    }
}
