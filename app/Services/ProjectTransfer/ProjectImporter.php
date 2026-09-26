<?php

namespace App\Services\ProjectTransfer;

use App\Discussion\Pipelines\PipelineRegistry;
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
        $missing = array_values(array_diff($exportedIds, $existingIds));

        return DB::transaction(function () use ($data, $projectData, $owner, $existingIds, $missing) {
            $project = new Project;
            $project->user_id = $owner->id;
            $project->title = trim((string) ($projectData['title'] ?? __('projects.import.untitled'))).' '.__('projects.import.copy_suffix');
            $project->description = $projectData['description'] ?? '';
            $project->pipeline = $this->knownPipeline($projectData['pipeline'] ?? null);
            $project->model = $this->knownModel($projectData['model'] ?? null);
            // Absent in schema < 5 (and null whenever the source followed the
            // configured defaults): stays null, so the copy does too.
            $project->summarize_threshold = $this->positiveOrNull($projectData['summarize_threshold'] ?? null);
            $project->summarize_oldest = $this->positiveOrNull($projectData['summarize_oldest'] ?? null);
            $project->long_term_memory = $projectData['long_term_memory'] ?? null;
            $project->save();
            $project->addContributingUser($owner);

            foreach ($data['experts'] ?? [] as $exported) {
                $expert = Expert::find((int) ($exported['id'] ?? 0));

                if ($expert !== null) {
                    $project->addContributingExpert($expert);
                }
            }

            // Recreate messages; track old→new ids to remap the summary watermark.
            $idMap = [];
            foreach ($data['messages'] ?? [] as $m) {
                $msg = new Message;
                $msg->project_id = $project->id;
                $msg->content = $m['content'] ?? '';

                $expertId = isset($m['expert_id']) ? (int) $m['expert_id'] : null;
                if ($expertId !== null && in_array($expertId, $existingIds, true)) {
                    $msg->expert_id = $expertId;
                } elseif (! empty($m['is_user'])) {
                    $msg->user_id = $owner->id;
                }
                // otherwise a system message: both ids stay null

                // Re-link the addressee, but only to an expert that survived
                // re-linking. No support for the old export format: a file from
                // before this change imports without addressees.
                $addresseeId = isset($m['addressee_expert_id']) ? (int) $m['addressee_expert_id'] : null;
                if ($addresseeId !== null && in_array($addresseeId, $existingIds, true)) {
                    $msg->addressee_expert_id = $addresseeId;
                }
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
                    'expert_id' => $expertId,
                    'content' => $s['content'] ?? '',
                ]);
            }

            // Remap the long-term memory watermark to the recreated message.
            $watermark = (int) ($projectData['summarized_until_message_id'] ?? 0);
            $project->summarized_until_message_id = $idMap[$watermark] ?? null;
            $project->save();

            return ['project' => $project, 'missing_experts' => $missing];
        });
    }

    private function knownPipeline(?string $name): string
    {
        $pipelines = app(PipelineRegistry::class);

        return $name !== null && $pipelines->has($name) ? $name : $pipelines->default();
    }

    private function knownModel(?string $key): string
    {
        // Array index, not dot notation: see ModelConfig::fromConfig().
        return $key !== null && isset(config('ai.models')[$key])
            ? $key
            : (string) config('ai.default_model');
    }

    /** Keeps only a usable per-project override; anything else falls back to the config. */
    private function positiveOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
