<?php

namespace App\Services\Transfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Writes a bundle: one project, one expert or everything. Dependencies always come along. */
final class BundleExporter
{
    public function __construct(
        private readonly TagTransfer $tags,
        private readonly ExpertTransfer $experts,
        private readonly ProjectTransfer $projects,
        private readonly GroupTransfer $groups,
        private readonly QuestionTransfer $questions,
    ) {}

    public function project(Project $project, string $path): void
    {
        $this->write(Bundle::KIND_PROJECT, collect([$project]), Expert::whereIn('id', $this->projects->expertIds($project))->get(), null, $path);
    }

    public function expert(Expert $expert, string $path): void
    {
        $this->write(Bundle::KIND_EXPERT, collect(), collect([$expert]), null, $path);
    }

    /** Every project and expert; the groups and questions of $user. */
    public function everything(User $user, string $path): void
    {
        $this->write(Bundle::KIND_GLOBAL, Project::orderBy('id')->get(), Expert::orderBy('id')->get(), $user, $path);
    }

    public function filename(string $kind, string $label): string
    {
        return 'communicat-'.$kind.'-'.Str::slug($label).'-'.now()->format('Y-m-d').'.zip';
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Expert>  $experts
     */
    private function write(string $kind, Collection $projects, Collection $experts, ?User $user, string $path): void
    {
        $writer = new BundleWriter($path);
        $writer->put(Bundle::MANIFEST, [
            'schema_version' => Bundle::SCHEMA_VERSION,
            'kind' => $kind,
            'exported_at' => now()->toIso8601String(),
            'counts' => ['projects' => $projects->count(), 'experts' => $experts->count()],
        ]);
        $writer->put(Bundle::TAGS, $this->tags->export());
        $writer->put(Bundle::EXPERTS, $experts->map(fn (Expert $expert) => $this->experts->export($expert))->values()->all());

        foreach ($projects as $project) {
            $writer->put(Bundle::projectEntry($project->uuid), $this->projects->export($project));
        }

        if ($user !== null) {
            $writer->put(Bundle::GROUPS, $this->groups->export($user));
            $writer->put(Bundle::QUESTIONS, $this->questions->export($user));
        }

        $writer->close();
    }
}
