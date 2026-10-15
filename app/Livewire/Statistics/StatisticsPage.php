<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\QuestionDefinition;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Question;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: everyone's templates and the user's own questions on
 * the left, the open question on the right. An own question is always
 * resolved through the user's own rows; someone else's does not exist here.
 * A template (a row with is_template) is opened by everyone to view and
 * copied to adjust. Only admins save templates, and only an admin who owns
 * one renames or deletes it: a template is read by everyone (QuestionPolicy
 * holds these rules; the page only looks rows up). The editor's
 * question-saved event renders the page again, so the list shows the new
 * sentence.
 */
#[On('question-saved')]
class StatisticsPage extends Component
{
    use NeedsConfirmation;

    #[Url(as: 'q')]
    public ?int $questionId = null;

    #[Locked]
    public ?int $renamingId = null;

    public string $name = '';

    /** Why a saved template differs from its question; shown until the user opens something else. */
    #[Locked]
    public ?string $templateNotice = null;

    public function mount(): void
    {
        if ($this->questionId !== null && Question::visibleTo($this->user())->find($this->questionId) === null) {
            $this->questionId = null;
        }
    }

    public function open(int $questionId): void
    {
        $this->templateNotice = null;
        $this->questionId = (Question::visibleTo($this->user())->find($questionId) ?? abort(404))->id;
    }

    public function create(): void
    {
        $this->questionId = $this->user()->questions()->create(['definition' => (new QuestionDefinition)->toArray()])->id;
    }

    /**
     * A template becomes the user's own question, to adjust, titled as a copy
     * so the two are told apart in the list; the template itself stays as it
     * is. The form being viewed may come along.
     */
    public function copyTemplate(int $templateId, ?string $form = null, ?string $curve = null): void
    {
        $template = Question::templates()->findOrFail($templateId);
        $definition = $template->definition()->toArray();

        if ($form !== null) {
            $definition['form'] = $form;
        }

        if ($curve !== null) {
            $definition['curve'] = $curve;
        }

        $this->questionId = $this->user()->questions()->create([
            'name' => Str::limit(__('questions.copy_name', ['name' => $template->name]), 100, ''),
            'notes' => $template->notes,
            'definition' => QuestionDefinition::fromArray($definition)->toArray(),
        ])->id;
    }

    /** A snapshot of the question as a template (without groups, which are personal), titled like it, or by its sentence when untitled; the question stays the user's own. Admins only. */
    public function saveAsTemplate(int $questionId): void
    {
        $question = $this->own($questionId);
        $this->authorize('saveAsTemplate', $question);
        $asked = $question->definition();
        $template = $asked->withoutGroups();

        $this->user()->questions()->create([
            'name' => Str::limit($question->name ?? app(QuestionSentence::class)->of($asked), 100, ''),
            'notes' => $question->notes,
            'definition' => $template->toArray(),
            'is_template' => true,
        ]);

        // Groups are drawers in one person's sidebar: for anyone else the template would match nothing.
        $this->templateNotice = $template == $asked ? null : __('questions.template.without_groups');
    }

    public function startRenaming(int $questionId): void
    {
        $question = $this->own($questionId);
        $this->authorize('update', $question);
        $this->renamingId = $question->id;
        $this->name = (string) $question->name;
        $this->resetValidation();
        Flux::modal('question-name')->show();
    }

    /**
     * An empty title is allowed for a question: it is then listed as
     * untitled. A template is read by everyone and keeps a title.
     */
    public function saveName(): void
    {
        $question = $this->own((int) $this->renamingId);
        $this->authorize('update', $question);
        $this->name = trim($this->name);
        $this->validate(['name' => [$question->is_template ? 'required' : 'nullable', 'string', 'max:100']], [], ['name' => __('questions.name.field')]);

        $question->update(['name' => $this->name === '' ? null : $this->name]);
        $this->dispatch('question-renamed');

        $this->reset(['renamingId', 'name']);
        Flux::modal('question-name')->close();
    }

    public function duplicate(int $questionId): void
    {
        $source = $this->own($questionId);
        $this->authorize('duplicate', $source);
        $copy = $source->replicate();
        $copy->name = $source->name === null ? null : Str::limit(__('questions.copy_name', ['name' => $source->name]), 100, '');
        $copy->save();

        $this->questionId = $copy->id;
    }

    /** wire:sort hands over the item key and its new 0-based position. */
    public function sortQuestion(int $questionId, int $position): void
    {
        $this->reorderable($questionId)->moveTo($position);
    }

    /** @param  int  $steps  -1 moves one place up, 1 one place down */
    public function moveQuestion(int $questionId, int $steps): void
    {
        $this->reorderable($questionId)->moveBy($steps);
    }

    /** The templates' order is everyone's, so only an admin sets it. */
    public function sortTemplate(int $templateId, int $position): void
    {
        $this->orderedTemplate($templateId)->moveTo($position);
    }

    public function moveTemplate(int $templateId, int $steps): void
    {
        $this->orderedTemplate($templateId)->moveBy($steps);
    }

    private function orderedTemplate(int $templateId): Question
    {
        $this->authorize('manageTemplates', Question::class);

        return Question::templates()->findOrFail($templateId);
    }

    public function confirmDelete(int $questionId): void
    {
        $question = $this->own($questionId);
        $this->authorize('delete', $question);
        $kind = $question->is_template ? 'delete_template' : 'delete';

        $this->confirmTitle = __("questions.confirm.{$kind}.title");
        $this->confirmMessage = __("questions.confirm.{$kind}.message", ['name' => $question->name ?? __('questions.untitled')]);
        $this->needsConfirmation('delete', $questionId);
    }

    /** Confirmed, so called directly, past Livewire's attributes: it authorizes itself. */
    public function delete(int $questionId): void
    {
        $question = $this->own($questionId);
        $this->authorize('delete', $question);
        $question->delete();

        if ($this->questionId === $questionId) {
            $this->questionId = null;
        }
    }

    /**
     * One of the user's own rows, question or template. Someone else's row is
     * not found at all; what the user may do with their own is QuestionPolicy's.
     */
    private function own(int $questionId): Question
    {
        return $this->user()->questions()->findOrFail($questionId);
    }

    private function reorderable(int $questionId): Question
    {
        $question = $this->own($questionId);
        $this->authorize('reorder', $question);

        return $question;
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render(): mixed
    {
        $questions = $this->user()->questions()->notTemplates()->get();

        $templates = Question::templates()->get();
        $definitions = $questions->concat($templates)->mapWithKeys(fn (Question $question) => [$question->id => $question->definition()]);
        $sentence = app(QuestionSentence::class)->prime($definitions);

        return view('livewire.statistics.statistics-page', [
            'questions' => $questions,
            'templates' => $templates,
            'sentences' => $definitions->map(fn (QuestionDefinition $definition) => $sentence->of($definition)),
            'mayShare' => Gate::allows('manageTemplates', Question::class),
            'current' => $this->questionId === null ? null : $questions->firstWhere('id', $this->questionId) ?? $templates->firstWhere('id', $this->questionId),
        ])->title(__('questions.heading'));
    }
}
