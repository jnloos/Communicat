<?php

namespace App\Services\Transfer;

use App\Discussion\Support\PersonaLines;
use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use App\Services\Tags\TagsByName;
use App\Services\Transfer\Rows\JobLogRow;
use App\Services\Transfer\Rows\PromptLogRow;
use App\Services\Transfer\Rows\Timestamp;

/**
 * Reads one run into a project file. Experts are named by uuid; expert_ids
 * maps every original id to its uuid, so an import can follow a renumbered
 * expert into the ids inside each turn's selection and run_config.cast.
 */
final class ProjectExporter
{
    public function __construct(private readonly TagsByName $tags = new TagsByName) {}

    /** @return list<int> */
    public function expertIds(Project $project): array
    {
        $jobs = JobLog::where('project_id', $project->id)->get(['id', 'expert_id']);
        $messages = Message::where('project_id', $project->id)->get(['expert_id', 'addressee_expert_id']);

        return collect()
            ->merge($project->experts()->pluck('experts.id'))
            ->merge($messages->pluck('expert_id'))
            ->merge($messages->pluck('addressee_expert_id'))
            ->merge($jobs->pluck('expert_id'))
            ->merge(PromptLog::whereIn('job_log_id', $jobs->pluck('id'))->pluck('expert_id'))
            ->merge(Summary::where('project_id', $project->id)->pluck('expert_id'))
            // The frozen cast names who sat where, including someone who left without speaking.
            ->merge(collect($project->run_config['cast'] ?? [])->map(fn ($member) => is_array($member) ? ($member['expert_id'] ?? null) : null))
            ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public function export(Project $project): array
    {
        $uuids = Expert::whereIn('id', $this->expertIds($project))->pluck('uuid', 'id');
        $uuid = fn ($id) => $id === null ? null : ($uuids[(int) $id] ?? null);

        return [
            'uuid' => $project->uuid,
            'title' => $project->title,
            'description' => $project->description,
            'pipeline' => $project->pipeline,
            'model' => $project->model,
            'seed' => $project->seed,
            'turn_budget' => $project->turn_budget,
            'run_config' => $project->run_config,
            'summarize_threshold' => $project->summarize_threshold,
            'summarize_oldest' => $project->summarize_oldest,
            // The live settings by attribute name, as the researcher left them; the frozen
            // value travels inside run_config.
            'attribute_visibility' => array_map(fn (AttributeVisibility $level) => $level->value, PersonaLines::liveVisibilitiesOf($project)),
            'long_term_memory' => $project->long_term_memory,
            'summarized_until_message' => $project->summarized_until_message_id,
            'created_at' => Timestamp::export($project->created_at),
            'tags' => $this->tags->export($project),
            'expert_ids' => $uuids->mapWithKeys(fn ($uuid, $id) => [(string) $id => $uuid])->all(),
            'seats' => $project->experts()->get()
                ->map(fn (Expert $expert) => ['expert' => $expert->uuid, 'seat' => $expert->pivot->seat === null ? null : (int) $expert->pivot->seat])
                ->values()->all(),
            'messages' => Message::where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (Message $message) => [
                    'key' => $message->id,
                    'content' => $message->content,
                    'expert' => $uuid($message->expert_id),
                    'is_user' => $message->user_id !== null,
                    'addressee' => $uuid($message->addressee_expert_id),
                    'job' => $message->job_log_id,
                    'created_at' => Timestamp::export($message->created_at),
                ])->values()->all(),
            'summaries' => Summary::where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (Summary $summary) => [
                    'expert' => $uuid($summary->expert_id),
                    'content' => $summary->content,
                    'updated_at' => Timestamp::export($summary->updated_at),
                ])->values()->all(),
            'jobs' => JobLog::with(['promptLogs' => fn ($query) => $query->orderBy('id'), 'promptLogs.archive'])
                ->where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (JobLog $job) => JobLogRow::toArray($job, $uuid) + [
                    'prompts' => $job->promptLogs->map(fn (PromptLog $log) => PromptLogRow::toArray($log, $uuid))->values()->all(),
                ])->values()->all(),
        ];
    }
}
