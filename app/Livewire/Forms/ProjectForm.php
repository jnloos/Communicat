<?php

namespace App\Livewire\Forms;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Models\Project;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * The fields a project's create and edit forms share: topic, pipeline, model
 * and the two summarization numbers. Tags stay on the component, because
 * x-tags.fields binds to the parent's own tagSelection.
 */
class ProjectForm extends Form
{
    /** Shared with ProjectTitleForm, the sidebar's rename, so a title means the same everywhere. */
    public const TITLE_RULES = ['required', 'string', 'max:255'];

    public string $title = '';

    public string $description = '';

    public string $pipeline = '';

    public string $model = '';

    /** UI state only: the provider filters the model dropdown and is never stored. */
    public string $provider = '';

    /** Free-typed suggestions, so both are kept as text and cast in attributes(). */
    public string $summarizeThreshold = '';

    public string $summarizeOldest = '';

    /** A new project starts on the configured pipeline, model and summarization. */
    public function fillDefaults(PipelineRegistry $pipelines): void
    {
        $this->pipeline = $pipelines->default();
        $this->selectModel((string) config('ai.default_model'));
        $this->summarizeThreshold = (string) config('discussion.summarize_threshold');
        $this->summarizeOldest = (string) config('discussion.summarize_oldest');
    }

    /** The summarization numbers as effective values, so a null column shows its default. */
    public function fillFrom(Project $project): void
    {
        $this->title = $project->title;
        $this->description = $project->description;
        $this->pipeline = $project->pipeline;
        $this->selectModel($project->model);
        $this->summarizeThreshold = (string) $project->summarizeThreshold();
        $this->summarizeOldest = (string) $project->summarizeOldest();
    }

    /**
     * The project columns this form writes, cast. Call after validate().
     *
     * @return array{title: string, description: string, pipeline: string, model: string, summarize_threshold: int, summarize_oldest: int}
     */
    public function attributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'pipeline' => $this->pipeline,
            'model' => $this->model,
            'summarize_threshold' => (int) $this->summarizeThreshold,
            'summarize_oldest' => (int) $this->summarizeOldest,
        ];
    }

    protected function rules(): array
    {
        return [
            'title' => self::TITLE_RULES,
            // The topic every prompt carries; without it a run refuses to start.
            'description' => ['required', 'string'],
            'pipeline' => ['required', Rule::in(array_keys(app(PipelineRegistry::class)->options()))],
            'provider' => ['required', Rule::in(array_keys(ModelConfig::providers()))],
            // Against the provider's own models, so a forged request cannot pair
            // a model with a provider it does not belong to.
            'model' => ['required', Rule::in(array_keys(ModelConfig::optionsFor($this->provider)))],
            'summarizeThreshold' => ['required', 'integer', 'min:2'],
            // Folding every pending message would leave no verbatim history at all.
            'summarizeOldest' => ['required', 'integer', 'min:1', 'lt:summarizeThreshold'],
        ];
    }

    /** A new provider invalidates the chosen model: move to its first one. Livewire calls this hook on the form itself. */
    public function updatedProvider(): void
    {
        $this->model = (string) array_key_first(ModelConfig::optionsFor($this->provider));
    }

    private function selectModel(string $model): void
    {
        $this->model = $model;
        $this->provider = ModelConfig::fromConfig($model)->provider;
    }
}
