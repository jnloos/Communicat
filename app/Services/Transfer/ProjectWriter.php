<?php

namespace App\Services\Transfer;

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
use Closure;

/**
 * Writes a checked project file as a new project. Inside the file, messages
 * and turns refer to each other by their original ids, which are local keys
 * and never written back as ids. Texts are never rewritten.
 */
final class ProjectWriter
{
    public function __construct(private readonly TagsByName $tags = new TagsByName) {}

    /**
     * @param  array<string, int>  $experts  uuid => id
     * @param  list<string>  $skippedTags  filled with the tag pairs that could not be placed
     */
    public function write(array $data, User $owner, array $experts, array &$skippedTags): Project
    {
        $expert = fn (?string $uuid) => $uuid === null ? null : $experts[$uuid];
        $renumbering = ExpertRenumbering::fromExpertIds($data['expert_ids'] ?? []);

        $project = $this->createProject($data, $owner, $renumbering);
        $skippedTags = $this->tags->assign($project, $data['tags'] ?? []);
        $this->attachSeats($project, $data['seats'] ?? [], $expert);
        $jobIds = $this->writeJobs($project, $data['jobs'] ?? [], $expert, $renumbering);
        $messageIds = $this->writeMessages($project, $data['messages'] ?? [], $owner, $expert, $jobIds);
        $this->writeSummaries($project, $data['summaries'] ?? [], $expert);

        $watermark = $data['summarized_until_message'] ?? null;
        $project->forceFill(['summarized_until_message_id' => $watermark === null ? null : ($messageIds[(int) $watermark] ?? null)])->save();

        return $project->fresh();
    }

    private function createProject(array $data, User $owner, ExpertRenumbering $renumbering): Project
    {
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

        return $project;
    }

    /** @param  Closure(?string): ?int  $expert  uuid => expert id here */
    private function attachSeats(Project $project, array $seats, Closure $expert): void
    {
        foreach ($seats as $seat) {
            $project->experts()->attach($expert($seat['expert']), ['seat' => $seat['seat']]);
        }
    }

    /**
     * @param  Closure(?string): ?int  $expert  uuid => expert id here
     * @return array<int, int> the file's job key => new job_logs id
     */
    private function writeJobs(Project $project, array $jobs, Closure $expert, ExpertRenumbering $renumbering): array
    {
        $jobIds = [];

        foreach ($jobs as $job) {
            $log = new JobLog;
            $log->forceFill(JobLogRow::attributes($job, $project->id, $expert, $renumbering))->save();
            $jobIds[(int) $job['key']] = $log->id;

            foreach ($job['prompts'] ?? [] as $prompt) {
                (new PromptLog)->forceFill(PromptLogRow::attributes($prompt, $log->id, $expert))->save();
            }
        }

        return $jobIds;
    }

    /**
     * @param  Closure(?string): ?int  $expert  uuid => expert id here
     * @param  array<int, int>  $jobIds  the file's job key => new job_logs id
     * @return array<int, int> the file's message key => new messages id
     */
    private function writeMessages(Project $project, array $messages, User $owner, Closure $expert, array $jobIds): array
    {
        $messageIds = [];

        foreach ($messages as $message) {
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

        return $messageIds;
    }

    /** @param  Closure(?string): ?int  $expert  uuid => expert id here */
    private function writeSummaries(Project $project, array $summaries, Closure $expert): void
    {
        foreach ($summaries as $summary) {
            (new Summary)->forceFill([
                'project_id' => $project->id,
                'expert_id' => $expert($summary['expert']),
                'content' => (string) ($summary['content'] ?? ''),
                'updated_at' => Timestamp::import($summary['updated_at'] ?? null) ?? now(),
            ])->save();
        }
    }
}
