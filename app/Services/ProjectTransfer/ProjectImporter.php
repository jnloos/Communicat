<?php

namespace App\Services\ProjectTransfer;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectImporter
{
    /**
     * Clone a project from an exported payload into a new project owned by $owner.
     *
     * Experts are re-linked by id within the same instance; ids that no longer
     * exist are skipped and returned so the caller can warn. User and system
     * messages are reassigned to $owner (the export carries no stable user id).
     *
     * @return array{project: Project, missing_experts: array<int, int>}
     */
    public function import(array $data, User $owner): array
    {
        $projectData = $data['project'] ?? [];

        $exportedIds = collect($data['experts'] ?? [])
            ->pluck('id')->filter()->map(fn ($i) => (int) $i)->all();
        $existingIds = Expert::whereIn('id', $exportedIds)->pluck('id')->all();
        $missing     = array_values(array_diff($exportedIds, $existingIds));

        return DB::transaction(function () use ($data, $projectData, $owner, $existingIds, $missing) {
            $project = new Project();
            $project->user_id     = $owner->id;
            $project->title       = trim((string) ($projectData['title'] ?? 'Projekt')) . ' ' . __('(Kopie)');
            $project->description = $projectData['description'] ?? '';
            $project->settings    = [];
            $project->save(); // creating/created hooks add a welcome message + sync the owner

            // Drop the auto-generated welcome message so the clone matches the source.
            $project->messages()->delete();

            if (! empty($existingIds)) {
                $project->experts()->syncWithoutDetaching($existingIds);
            }

            // Recreate messages; track old→new ids to remap the summary watermark.
            $idMap = [];
            foreach ($data['messages'] ?? [] as $m) {
                $msg = new Message();
                $msg->project_id = $project->id;
                $msg->content    = $m['content'] ?? '';

                $expertId = isset($m['expert_id']) ? (int) $m['expert_id'] : null;
                if ($expertId !== null && in_array($expertId, $existingIds, true)) {
                    $msg->expert_id = $expertId;
                } elseif (! empty($m['is_user'])) {
                    $msg->user_id = $owner->id;
                }
                // otherwise a system message: both ids stay null

                $msg->adjacency_pair_type = $m['adjacency_pair_type'] ?? null;
                $msg->next_speaker        = $m['next_speaker'] ?? null;
                if (! empty($m['created_at'])) {
                    $msg->created_at = Carbon::parse($m['created_at']);
                }
                $msg->save();

                if (isset($m['id'])) {
                    $idMap[(int) $m['id']] = $msg->id;
                }
            }

            // Recreate per-expert memory for re-linked experts only.
            foreach ($data['summaries'] ?? [] as $s) {
                $expertId = isset($s['expert_id']) ? (int) $s['expert_id'] : null;
                if ($expertId === null || ! in_array($expertId, $existingIds, true)) {
                    continue;
                }
                Summary::create([
                    'project_id' => $project->id,
                    'expert_id'  => $expertId,
                    'content'    => $s['content'] ?? '',
                ]);
            }

            // Carry settings over, remapping the summarization watermark to the new id.
            $settings = $projectData['settings'] ?? [];
            if (! empty($settings['last_summarized_id'])) {
                $settings['last_summarized_id'] = $idMap[(int) $settings['last_summarized_id']] ?? 0;
            }
            $project->settings = $settings;
            $project->save();

            return ['project' => $project, 'missing_experts' => $missing];
        });
    }
}
