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
use Illuminate\Support\Carbon;
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
 * inside each turn's selection follow it: speaker_id, the keys of
 * signals.bids, the tokens in signals.fallbacks and signals.token, the values
 * of signals.order. An id the file does not map stays as it is. Texts are
 * never rewritten: E-tokens inside logged prompts, answers, the selector's
 * reasoning and messages keep naming the source ids.
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
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public function export(Project $project): array
    {
        $uuids = Expert::whereIn('id', $this->expertIds($project))->pluck('uuid', 'id');
        $uuid = fn ($id) => $id === null ? null : ($uuids[(int) $id] ?? null);
        $time = fn ($value) => $value === null ? null : Carbon::parse($value)->toIso8601String();

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
            'created_at' => $time($project->created_at),
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
                    'created_at' => $time($message->created_at),
                ])->values()->all(),
            'summaries' => Summary::where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (Summary $summary) => [
                    'expert' => $uuid($summary->expert_id),
                    'content' => $summary->content,
                    'updated_at' => $time($summary->updated_at),
                ])->values()->all(),
            'jobs' => JobLog::with(['promptLogs' => fn ($query) => $query->orderBy('id')])
                ->where('project_id', $project->id)->orderBy('id')->get()
                ->map(fn (JobLog $job) => [
                    'key' => $job->id,
                    'job_class' => $job->job_class,
                    'status' => $job->status,
                    'payload' => $job->payload,
                    'turn_index' => $job->turn_index,
                    'expert' => $uuid($job->expert_id),
                    'seat' => $job->seat,
                    'words' => $job->words,
                    'chars' => $job->chars,
                    'thought_words' => $job->thought_words,
                    'thought_chars' => $job->thought_chars,
                    'reasoning_tokens' => $job->reasoning_tokens,
                    'selection' => $job->selection,
                    'error' => $job->error,
                    'started_at' => $time($job->started_at),
                    'finished_at' => $time($job->finished_at),
                    'prompts' => $job->promptLogs->map(fn (PromptLog $log) => [
                        'label' => $log->label,
                        'model' => $log->model,
                        'prompt' => $log->prompt,
                        'response' => $log->response,
                        'latency_ms' => $log->latency_ms,
                        'provider' => $log->provider,
                        'checkpoint' => $log->checkpoint,
                        'config' => $log->config,
                        'purpose' => $log->purpose,
                        'expert' => $uuid($log->expert_id),
                        'reasoning' => $log->reasoning,
                        'tokens_in' => $log->tokens_in,
                        'tokens_out' => $log->tokens_out,
                        'tokens_reasoning' => $log->tokens_reasoning,
                        'status' => $log->status,
                        'error' => $log->error,
                        'created_at' => $time($log->created_at),
                    ])->values()->all(),
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

    /** @return array<int, int> original id => id here, only for experts whose id changed */
    private function renumberedIds(array $data): array
    {
        $original = collect($data['expert_ids'] ?? [])->filter(fn ($uuid) => is_string($uuid));
        $here = Expert::whereIn('uuid', $original->values())->pluck('id', 'uuid');
        $renumbered = [];

        foreach ($original as $oldId => $uuid) {
            $newId = isset($here[$uuid]) ? (int) $here[$uuid] : null;

            if ($newId !== null && $newId !== (int) $oldId) {
                $renumbered[(int) $oldId] = $newId;
            }
        }

        return $renumbered;
    }

    /**
     * The selection with every expert id the map knows replaced; built fresh,
     * so a chain like 1 → 2, 2 → 3 cannot overwrite itself.
     *
     * @param  array<int, int>  $renumbered
     */
    private function rewriteSelection(?array $selection, array $renumbered): ?array
    {
        if ($selection === null || $renumbered === []) {
            return $selection;
        }

        $id = fn ($old) => is_int($old) || (is_string($old) && ctype_digit($old)) ? ($renumbered[(int) $old] ?? $old) : $old;
        $token = fn ($old) => is_string($old) && preg_match('/^E(\d+)$/D', $old, $match) && isset($renumbered[(int) $match[1]])
            ? 'E'.$renumbered[(int) $match[1]]
            : $old;

        if (array_key_exists('speaker_id', $selection)) {
            $selection['speaker_id'] = $id($selection['speaker_id']);
        }

        $signals = $selection['signals'] ?? null;

        if (! is_array($signals)) {
            return $selection;
        }

        if (is_array($signals['bids'] ?? null)) {
            $bids = [];

            foreach ($signals['bids'] as $bidder => $bid) {
                $bids[$token((string) $bidder)] = $bid;
            }

            $signals['bids'] = $bids;
        }

        if (is_array($signals['fallbacks'] ?? null)) {
            $signals['fallbacks'] = array_map($token, $signals['fallbacks']);
        }

        if (array_key_exists('token', $signals)) {
            $signals['token'] = $token($signals['token']);
        }

        if (is_array($signals['order'] ?? null)) {
            $signals['order'] = array_map($id, $signals['order']);
        }

        $selection['signals'] = $signals;

        return $selection;
    }

    /**
     * @param  array<string, int>  $experts  uuid => id
     * @param  list<string>  $skippedTags  filled with the tag pairs that could not be placed
     */
    private function write(array $data, User $owner, array $experts, array &$skippedTags): Project
    {
        $expert = fn (?string $uuid) => $uuid === null ? null : $experts[$uuid];
        $renumbered = $this->renumberedIds($data);
        $time = fn ($value) => $value === null ? null : Carbon::parse($value);

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
            'run_config' => $data['run_config'] ?? null,
            'summarize_threshold' => $data['summarize_threshold'] ?? null,
            'summarize_oldest' => $data['summarize_oldest'] ?? null,
            'long_term_memory' => $data['long_term_memory'] ?? null,
            'created_at' => $time($data['created_at'] ?? null) ?? now(),
        ])->save();

        $project->addContributingUser($owner);
        $skippedTags = $this->tags->assign($project, $data['tags'] ?? []);

        foreach ($data['seats'] ?? [] as $seat) {
            $project->experts()->attach($expert($seat['expert']), ['seat' => $seat['seat']]);
        }

        $jobIds = [];

        foreach ($data['jobs'] ?? [] as $job) {
            $log = new JobLog;
            $log->forceFill([
                'project_id' => $project->id,
                'job_class' => (string) ($job['job_class'] ?? ''),
                'status' => (string) ($job['status'] ?? 'failed'),
                'payload' => $job['payload'] ?? null,
                'turn_index' => $job['turn_index'] ?? null,
                'expert_id' => $expert($job['expert'] ?? null),
                'seat' => $job['seat'] ?? null,
                'words' => $job['words'] ?? null,
                'chars' => $job['chars'] ?? null,
                'thought_words' => $job['thought_words'] ?? null,
                'thought_chars' => $job['thought_chars'] ?? null,
                'reasoning_tokens' => $job['reasoning_tokens'] ?? null,
                'selection' => $this->rewriteSelection($job['selection'] ?? null, $renumbered),
                'error' => $job['error'] ?? null,
                'started_at' => $time($job['started_at'] ?? null) ?? now(),
                'finished_at' => $time($job['finished_at'] ?? null),
                'created_at' => $time($job['started_at'] ?? null) ?? now(),
            ])->save();
            $jobIds[(int) $job['key']] = $log->id;

            foreach ($job['prompts'] ?? [] as $prompt) {
                (new PromptLog)->forceFill([
                    'job_log_id' => $log->id,
                    'label' => $prompt['label'] ?? null,
                    'model' => (string) ($prompt['model'] ?? ''),
                    'prompt' => (string) ($prompt['prompt'] ?? ''),
                    'response' => (string) ($prompt['response'] ?? ''),
                    'latency_ms' => $prompt['latency_ms'] ?? null,
                    'provider' => $prompt['provider'] ?? null,
                    'checkpoint' => $prompt['checkpoint'] ?? null,
                    'config' => $prompt['config'] ?? null,
                    'purpose' => $prompt['purpose'] ?? null,
                    'expert_id' => $expert($prompt['expert'] ?? null),
                    'reasoning' => $prompt['reasoning'] ?? null,
                    'tokens_in' => $prompt['tokens_in'] ?? null,
                    'tokens_out' => $prompt['tokens_out'] ?? null,
                    'tokens_reasoning' => $prompt['tokens_reasoning'] ?? null,
                    'status' => (string) ($prompt['status'] ?? 'ok'),
                    'error' => $prompt['error'] ?? null,
                    'created_at' => $time($prompt['created_at'] ?? null) ?? now(),
                ])->save();
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
                'created_at' => $time($message['created_at'] ?? null) ?? now(),
            ])->save();
            $messageIds[(int) $message['key']] = $row->id;
        }

        foreach ($data['summaries'] ?? [] as $summary) {
            (new Summary)->forceFill([
                'project_id' => $project->id,
                'expert_id' => $expert($summary['expert']),
                'content' => (string) ($summary['content'] ?? ''),
                'updated_at' => $time($summary['updated_at'] ?? null) ?? now(),
            ])->save();
        }

        $watermark = $data['summarized_until_message'] ?? null;
        $project->forceFill(['summarized_until_message_id' => $watermark === null ? null : ($messageIds[(int) $watermark] ?? null)])->save();

        return $project->fresh();
    }
}
