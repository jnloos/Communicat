<?php

namespace App\Console\Commands;

use App\Actions\Projects\OpenProject;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\User;
use App\Services\Tags\TagNames;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create a discussion from the console, fully specified.
 *
 * Everything that makes a run comparable is an explicit option, including the
 * seed: a run reproduces only if the tie-breaker draws the same way, and
 * Project::booted() would otherwise hand out a random one.
 */
class CreateProject extends Command
{
    protected $signature = 'project:create
        {title : The discussion topic}
        {--description= : The topic the agents discuss (required; the title never reaches a prompt)}
        {--owner= : Owner user id; defaults to the first admin, else the first user}
        {--experts= : Comma-separated expert ids to seat, in seat order}
        {--pipeline= : Short class name from app/Discussion/Pipelines}
        {--model= : A key from config ai.models}
        {--attributes= : How much of each persona attribute the prompts show: one level (none, own, all) for every attribute, or Name=level,... (default: Role=all, the rest none)}
        {--turns= : Turn budget; omit for unlimited}
        {--seed= : Tie-breaker seed; omit for a random one}';

    protected $description = 'Create a discussion project with its pipeline, model, seat order and seed.';

    public function handle(PipelineRegistry $pipelines, OpenProject $openProject): int
    {
        if (! $this->optionsAreValid()) {
            return self::FAILURE;
        }

        $owner = $this->resolveOwner();

        if ($owner === null) {
            $this->error('No user to own the project. Create one with auth:create-user.');

            return self::FAILURE;
        }

        $pipeline = $this->option('pipeline');

        if ($pipeline !== null && ! $pipelines->has($pipeline)) {
            $this->error("Unknown pipeline [{$pipeline}]. Known: ".implode(', ', array_keys($pipelines->options())));

            return self::FAILURE;
        }

        $model = $this->option('model');

        if ($model !== null && ! array_key_exists($model, config('ai.models'))) {
            $this->error("Unknown model [{$model}]. Known: ".implode(', ', array_keys(config('ai.models'))));

            return self::FAILURE;
        }

        $visibilities = $this->resolveVisibilities();

        if ($visibilities === null) {
            return self::FAILURE;
        }

        $experts = $this->resolveExperts();

        if ($experts === null) {
            return self::FAILURE;
        }

        $project = $openProject->handle($owner, array_filter([
            'title' => $this->argument('title'),
            'description' => trim($this->option('description')),
            'pipeline' => $pipeline,
            'model' => $model,
            'turn_budget' => $this->option('turns') === null ? null : (int) $this->option('turns'),
            'seed' => $this->option('seed') === null ? null : (int) $this->option('seed'),
        ], fn ($value) => $value !== null), $experts, visibilities: $visibilities);

        $this->info("Project {$project->id} created.");
        $this->table(['', ''], [
            ['Title', $project->title],
            ['Owner', $owner->email],
            ['Pipeline', $project->pipeline],
            ['Model', $project->model],
            ['Seed', $project->seed],
            ['Turn budget', $project->turn_budget ?? 'unlimited'],
            ['Seated', $project->contributingExperts()->pluck('name')->implode(', ') ?: '—'],
        ]);

        return self::SUCCESS;
    }

    /**
     * The levels by attribute id: the defaults when the option is omitted, one
     * level for every defined attribute, or `Name=level` pairs (an attribute not
     * named stays none).
     *
     * @return array<int, AttributeVisibility>|null null on an error the caller should report
     */
    private function resolveVisibilities(): ?array
    {
        $option = $this->option('attributes');

        if ($option === null) {
            return Project::defaultAttributeVisibilities();
        }

        $definitions = PersonaAttribute::ordered()->get();
        $known = implode(', ', array_column(AttributeVisibility::cases(), 'value'));

        if (! str_contains($option, '=')) {
            $level = AttributeVisibility::tryFrom(trim($option));

            if ($level === null) {
                $this->error("Unknown attribute visibility [{$option}]. Known: {$known}");

                return null;
            }

            return $definitions->mapWithKeys(fn (PersonaAttribute $a) => [$a->id => $level])->all();
        }

        $levels = $definitions->mapWithKeys(fn (PersonaAttribute $a) => [$a->id => AttributeVisibility::None])->all();

        foreach (array_filter(array_map('trim', explode(',', $option)), fn ($pair) => $pair !== '') as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $attribute = $definitions->first(fn (PersonaAttribute $a) => TagNames::same($a->name, $name));
            $level = AttributeVisibility::tryFrom(trim($value));

            if ($attribute === null) {
                $this->error('Unknown attribute ['.trim($name).']. Defined: '.($definitions->pluck('name')->implode(', ') ?: 'none'));

                return null;
            }

            if ($level === null) {
                $this->error('Unknown attribute visibility ['.trim($value)."] for [{$attribute->name}]. Known: {$known}");

                return null;
            }

            $levels[$attribute->id] = $level;
        }

        return $levels;
    }

    /**
     * The description is the topic every prompt carries; without one a run
     * cannot start (see TurnRunner). A budget or seed that is not a whole
     * number would otherwise be cast to 0 without a word.
     */
    private function optionsAreValid(): bool
    {
        $validator = Validator::make(
            [
                'description' => $this->option('description'),
                'turns' => $this->option('turns'),
                'seed' => $this->option('seed'),
            ],
            [
                'description' => ['required', 'string'],
                'turns' => ['nullable', 'integer', 'min:1'],
                'seed' => ['nullable', 'integer'],
            ],
        );

        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return $validator->passes();
    }

    private function resolveOwner(): ?User
    {
        if ($this->option('owner') !== null) {
            $owner = User::find((int) $this->option('owner'));

            if ($owner === null) {
                $this->error("No user with id {$this->option('owner')}.");
            }

            return $owner;
        }

        return User::where('is_admin', true)->orderBy('id')->first() ?? User::orderBy('id')->first();
    }

    /**
     * @return list<Expert>|null null on an error the caller should report
     */
    private function resolveExperts(): ?array
    {
        if ($this->option('experts') === null) {
            return [];
        }

        $ids = array_filter(array_map('trim', explode(',', $this->option('experts'))), fn ($id) => $id !== '');

        if (count($ids) > Project::MAX_CONTRIBUTING_EXPERTS) {
            $this->error('At most '.Project::MAX_CONTRIBUTING_EXPERTS.' experts can be seated.');

            return null;
        }

        $experts = [];

        foreach ($ids as $id) {
            $expert = Expert::find((int) $id);

            if ($expert === null) {
                $this->error("No expert with id {$id}.");

                return null;
            }

            $experts[] = $expert;
        }

        return $experts;
    }
}
