<?php

namespace App\Services\Transfer;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use App\Models\User;
use App\Services\Tags\TagsByName;
use App\Services\Transfer\Rows\JobLogRow;
use App\Services\Transfer\Rows\PromptLogRow;
use App\Services\Transfer\Rows\Timestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One run, complete: settings, frozen run_config, seats, messages, both memory
 * layers, the per-turn measurement and every logged call. Experts are named by
 * uuid; inside the file, messages and turns refer to each other by their
 * original ids, which are local keys and never written back as ids.
 *
 * An expert whose id was taken in the target database is renumbered on import
 * (ExpertTransfer). expert_ids maps every original id to its uuid, so the ids
 * inside each turn's selection and in run_config.cast follow it
 * (ExpertRenumbering). Texts are never rewritten.
 */
final class ProjectTransfer
{
    public function __construct(private readonly TagsByName $tags = new TagsByName) {}

    /** @return list<int> */
    public function expertIds(Project $project): array
    {
        $jobIds = JobLog::where('project_id', $project->id)->pluck('id');

        return collect()
            ->merge($project->experts()->pluck('experts.id'))
            ->merge(Message::where('project_id', $project->id)->pluck('expert_id'))
            ->merge(Message::where('project_id', $project->id)->pluck('addressee_expert_id'))
            ->merge(JobLog::where('project_id', $project->id)->pluck('expert_id'))
            ->merge(PromptLog::whereIn('job_log_id', $jobIds)->pluck('expert_id'))
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
            'jobs' => JobLog::with(['promptLogs' => fn ($query) => $query->orderBy('id')])
                ->where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (JobLog $job) => JobLogRow::toArray($job, $uuid) + [
                    'prompts' => $job->promptLogs->map(fn (PromptLog $log) => PromptLogRow::toArray($log, $uuid))->values()->all(),
                ])->values()->all(),
        ];
    }

    public function import(array $data, User $owner, ImportReport $report): ?Project
    {
        $title = (string) ($data['title'] ?? '');
        $reason = $this->reasonToSkip($data);

        if ($reason !== null) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::SKIPPED, $reason);

            return null;
        }

        $skippedTags = [];

        try {
            $project = DB::transaction(function () use ($data, $owner, &$skippedTags) {
                return $this->write($data, $owner, $this->expertIdsByUuid($data), $skippedTags);
            });
        } catch (Throwable $exception) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::FAILED, $exception->getMessage());

            return null;
        }

        $report->add(
            ImportReport::PROJECTS,
            $title,
            ImportReport::CREATED,
            $skippedTags === [] ? null : __('transfer.notes.tags_skipped', ['tags' => implode(', ', $skippedTags)]),
        );

        return $project;
    }

    private function reasonToSkip(array $data): ?string
    {
        $uuid = (string) ($data['uuid'] ?? '');

        return match (true) {
            $uuid === '' => __('transfer.notes.no_uuid'),
            ! Str::isUuid($uuid) => __('transfer.notes.bad_uuid'),
            Project::where('uuid', $uuid)->exists() => __('transfer.notes.already_there'),
            ! app(PipelineRegistry::class)->has((string) ($data['pipeline'] ?? '')) => __('transfer.notes.unknown_pipeline', ['pipeline' => $data['pipeline'] ?? '']),
            ! isset(config('ai.models')[(string) ($data['model'] ?? '')]) => __('transfer.notes.unknown_model', ['model' => $data['model'] ?? '']),
            ($missing = $this->missingExperts($data)) !== [] => __('transfer.notes.missing_experts', ['experts' => implode(', ', $missing)]),
            default => null,
        };
    }

    /** @return list<string> uuids the file names that are neither here nor imported before */
    private function missingExperts(array $data): array
    {
        $named = $this->namedExperts($data);

        return array_values(array_diff($named, Expert::whereIn('uuid', $named)->pluck('uuid')->all()));
    }

    /** @return list<string> */
    private function namedExperts(array $data): array
    {
        $named = [];

        foreach ($data['seats'] ?? [] as $seat) {
            $named[] = $seat['expert'] ?? null;
        }

        foreach ($data['messages'] ?? [] as $message) {
            $named[] = $message['expert'] ?? null;
            $named[] = $message['addressee'] ?? null;
        }

        foreach ($data['summaries'] ?? [] as $summary) {
            $named[] = $summary['expert'] ?? null;
        }

        foreach ($data['jobs'] ?? [] as $job) {
            $named[] = $job['expert'] ?? null;

            foreach ($job['prompts'] ?? [] as $prompt) {
                $named[] = $prompt['expert'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($named)));
    }

    /** @return array<string, int> */
    private function expertIdsByUuid(array $data): array
    {
        return Expert::whereIn('uuid', $this->namedExperts($data))->pluck('id', 'uuid')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<string, int>  $experts  uuid => id
     * @param  list<string>  $skippedTags  filled with the tag pairs that could not be placed
     */
    private function write(array $data, User $owner, array $experts, array &$skippedTags): Project
    {
        $expert = fn (?string $uuid) => $uuid === null ? null : $experts[$uuid];
        $renumbering = ExpertRenumbering::fromExpertIds($data['expert_ids'] ?? []);

        $project = new Project;
        $project->forceFill([
            'uuid' => $data['uuid'],
            'user_id' => $owner->id,
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'pipeline' => $data['pipeline'],
            'model' => $data['model'],
            'seed' => $data['seed'] ?? null,
            'turn_budget' => $data['turn_budget'] ?? null,
            'run_config' => $renumbering->runConfig($data['run_config'] ?? null),
            'summarize_threshold' => $data['summarize_threshold'] ?? null,
            'summarize_oldest' => $data['summarize_oldest'] ?? null,
            'long_term_memory' => $data['long_term_memory'] ?? null,
            'created_at' => Timestamp::import($data['created_at'] ?? null) ?? now(),
        ])->save();

        $project->addContributingUser($owner);
        $skippedTags = $this->tags->assign($project, $data['tags'] ?? []);

        foreach ($data['seats'] ?? [] as $seat) {
            $project->experts()->attach($expert($seat['expert']), ['seat' => $seat['seat']]);
        }

        $jobIds = [];

        foreach ($data['jobs'] ?? [] as $job) {
            $log = new JobLog;
            $log->forceFill(JobLogRow::attributes($job, $project->id, $expert, $renumbering))->save();
            $jobIds[(int) $job['key']] = $log->id;

            foreach ($job['prompts'] ?? [] as $prompt) {
                (new PromptLog)->forceFill(PromptLogRow::attributes($prompt, $log->id, $expert))->save();
            }
        }

        $messageIds = [];

        foreach ($data['messages'] ?? [] as $message) {
            $row = new Message;
            $row->forceFill([
                'project_id' => $project->id,
                'content' => (string) ($message['content'] ?? ''),
                'expert_id' => $expert($message['expert'] ?? null),
                'user_id' => ! empty($message['is_user']) ? $owner->id : null,
                'addressee_expert_id' => $expert($message['addressee'] ?? null),
                'job_log_id' => isset($message['job']) ? ($jobIds[(int) $message['job']] ?? null) : null,
                'created_at' => Timestamp::import($message['created_at'] ?? null) ?? now(),
            ])->save();
            $messageIds[(int) $message['key']] = $row->id;
        }

        foreach ($data['summaries'] ?? [] as $summary) {
            (new Summary)->forceFill([
                'project_id' => $project->id,
                'expert_id' => $expert($summary['expert']),
                'content' => (string) ($summary['content'] ?? ''),
                'updated_at' => Timestamp::import($summary['updated_at'] ?? null) ?? now(),
            ])->save();
        }

        $watermark = $data['summarized_until_message'] ?? null;
        $project->forceFill(['summarized_until_message_id' => $watermark === null ? null : ($messageIds[(int) $watermark] ?? null)])->save();

        return $project->fresh();
    }
}
