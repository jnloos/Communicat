<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\DeleteProject;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Livewire\Forms\ProjectForm;
use App\Models\JobLog;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EditProject extends Component
{
    use NeedsConfirmation;

    #[Locked]
    public int $forProjectId;

    public ProjectForm $form;

    /** @var array<int|string, int|string|null> bound by x-tags.fields; category id => value id */
    public array $tagSelection = [];

    /** Only informs the user that edits mid-run are recorded — nothing is frozen. */
    public bool $runStarted = false;

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
        $this->form->fillFrom($project);
        $this->runStarted = $project->run_config !== null;
        $this->tagSelection = $project->tagSelection();
    }

    #[On('edit_project')]
    public function select(): void
    {
        // Every opening starts from what is saved: edits dropped with "Discard" must not come back.
        $project = Project::findOrFail($this->forProjectId);
        $this->form->fillFrom($project);
        $this->tagSelection = $project->tagSelection();
        $this->resetValidation();

        Flux::modal('edit-project')->show();
    }

    public function save(): void
    {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('update', $project);
        $this->form->validate();
        $this->refuseVisibilityChangeAfterStart($project);
        $attributes = $this->form->attributes();

        $this->noteRunConfigChanges($project, $attributes);
        $project->fill($attributes);

        // One transaction, so a forged tag value leaves the project as it was.
        DB::transaction(function () use ($project) {
            $project->save();
            $project->setAttributeVisibilities($this->form->attributeLevels());
            $project->syncTags($this->tagSelection);
        });

        $this->dispatch('project_edited');
        Flux::modal('edit-project')->close();
    }

    /**
     * Unlike pipeline and model, the attribute visibility is not logged but fixed:
     * it changes what every prompt says, and run_config freezes it with the first turn.
     */
    private function refuseVisibilityChangeAfterStart(Project $project): void
    {
        $saved = array_map(fn ($level) => $level->value, $project->attributeVisibilities());
        $changed = array_diff_assoc($this->form->attributeVisibility, $saved) !== [];

        if ($project->run_config !== null && $changed) {
            $this->form->attributesOpen = true;

            throw ValidationException::withMessages([
                'form.attributeVisibility' => __('projects.fields.attribute_visibility_locked'),
            ]);
        }
    }

    /**
     * run_config describes the run as of its first turn. Pipeline, model and the two
     * summarization numbers all stay editable, so a change mid-run would make that
     * snapshot claim a condition that no longer holds: append one entry per change
     * instead, naming the turn it took effect after. Called before the new values
     * are assigned, with the form's attributes() as $attributes.
     *
     * The summarization numbers are the ones that truly depend on this entry. The
     * model leaves a trail in prompt_logs.config on every single call and the
     * mechanism in job_logs.selection on every turn, so a switch of either can be
     * reconstructed from the data alone — these two appear nowhere else.
     *
     * The numbers are compared as effective values (the accessors fall back to the
     * configured default), so filling a null column with the value it already had
     * is not a change of condition and records nothing.
     *
     * after_turn is the highest turn index that exists when the change is saved. A
     * turn still running may pick the new values up, so read it as "from this turn
     * onwards", not "strictly after it".
     *
     * @param  array<string, mixed>  $attributes
     */
    private function noteRunConfigChanges(Project $project, array $attributes): void
    {
        if ($project->run_config === null) {
            return;
        }

        $fields = [
            'pipeline' => [$project->pipeline, $attributes['pipeline']],
            'model' => [$project->model, $attributes['model']],
            'summarize_threshold' => [$project->summarizeThreshold(), $attributes['summarize_threshold']],
            'summarize_oldest' => [$project->summarizeOldest(), $attributes['summarize_oldest']],
        ];

        $changed = array_filter($fields, fn (array $pair) => $pair[0] !== $pair[1]);

        if ($changed === []) {
            return;
        }

        $afterTurn = (int) JobLog::where('project_id', $project->id)->max('turn_index');
        $snapshot = $project->run_config;

        foreach ($changed as $field => [$from, $to]) {
            $snapshot['changes'][] = [
                'after_turn' => $afterTurn,
                'field' => $field,
                'from' => $from,
                'to' => $to,
            ];
        }

        $project->run_config = $snapshot;
    }

    /** The same texts as the sidebar's delete (groups.confirm.delete_project). */
    public function confirmDelete(): void
    {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('delete', $project);

        $this->confirmTitle = __('groups.confirm.delete_project.title');
        $this->confirmLabel = __('groups.confirm.delete_project.action');
        $this->confirmMessage = __('groups.confirm.delete_project.message', ['name' => $project->title]);
        $this->needsConfirmation('delete');
    }

    public function delete(): void
    {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('delete', $project);
        app(DeleteProject::class)->handle($project);
        Flux::modal('edit-project')->close();
        $this->redirectRoute('dashboard');
    }

    public function render(): mixed
    {
        return view('livewire.projects.edit-project', [
            'pipelines' => app(PipelineRegistry::class)->options(),
            'providers' => ModelConfig::providers(),
            'models' => ModelConfig::optionsFor($this->form->provider),
            'definitions' => PersonaAttribute::ordered()->pluck('name', 'id')->all(),
            'tagCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'chosenTags' => TagValue::chosenIn(TagScope::Project, $this->tagSelection),
            'savedTags' => Project::findOrFail($this->forProjectId)->tagValues()->with('category')->get(),
        ]);
    }
}
