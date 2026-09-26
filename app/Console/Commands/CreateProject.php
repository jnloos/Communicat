<?php

namespace App\Console\Commands;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;

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
        {--description= : The longer framing of the topic}
        {--owner= : Owner user id; defaults to the first admin, else the first user}
        {--experts= : Comma-separated expert ids to seat, in seat order}
        {--pipeline= : Short class name from app/Discussion/Pipelines}
        {--model= : A key from config ai.models}
        {--turns= : Turn budget; omit for unlimited}
        {--seed= : Tie-breaker seed; omit for a random one}';

    protected $description = 'Create a discussion project with its pipeline, model, seat order and seed.';

    public function handle(PipelineRegistry $pipelines): int
    {
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

        $experts = $this->resolveExperts();

        if ($experts === null) {
            return self::FAILURE;
        }

        $project = Project::create(array_filter([
            'title' => $this->argument('title'),
            'description' => $this->option('description') ?? '',
            'user_id' => $owner->id,
            'pipeline' => $pipeline,
            'model' => $model,
            'turn_budget' => $this->option('turns') === null ? null : (int) $this->option('turns'),
            'seed' => $this->option('seed') === null ? null : (int) $this->option('seed'),
        ], fn ($value) => $value !== null));

        foreach ($experts as $expert) {
            $project->addContributingExpert($expert);
        }

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
