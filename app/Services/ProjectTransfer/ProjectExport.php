<?php

namespace App\Services\ProjectTransfer;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;

class ProjectExport
{
    /** Current export schema version (consumed by ProjectImporter). */
    public const SCHEMA_VERSION = 5;

    /**
     * Build the full clone payload for a project: pipeline/model config,
     * summarization parameters, contributing experts (in seat order), all
     * messages with metadata, and per-expert memory (summaries). The shape is
     * the contract consumed by {@see ProjectImporter::import()}.
     */
    public function toArray(Project $project): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'project' => [
                'title' => $project->title,
                'description' => $project->description,
                'pipeline' => $project->pipeline,
                'model' => $project->model,
                // The raw columns, not the effective values: a project that
                // follows the configured defaults must keep following them.
                'summarize_threshold' => $project->summarize_threshold,
                'summarize_oldest' => $project->summarize_oldest,
                'long_term_memory' => $project->long_term_memory,
                'summarized_until_message_id' => $project->summarized_until_message_id,
                'created_at' => optional($project->created_at)->toIso8601String(),
            ],
            'experts' => $project->contributingExperts()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'job' => $e->job,
                    'description' => $e->description,
                    'avatar_url' => $e->avatar_url,
                ])
                ->values()
                ->all(),
            'messages' => $project->messages()
                ->with(['expert:id,name', 'user:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'content' => $m->content,
                    'expert_id' => $m->expert_id,
                    'is_user' => $m->user_id !== null,
                    'sender_name' => $m->expert?->name ?? $m->user?->name,
                    // Polymorphic addressee, flattened: the expert id (re-linkable)
                    // or a user flag (reassigned to the importing owner).
                    'adjacency_partner_expert_id' => $m->adjacency_partner_type === Expert::class ? $m->adjacency_partner_id : null,
                    'adjacency_partner_is_user' => $m->adjacency_partner_type === User::class,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'summaries' => $project->summaries()
                ->get()
                ->map(fn ($s) => [
                    'expert_id' => $s->expert_id,
                    'content' => $s->content,
                ])
                ->values()
                ->all(),
        ];
    }

    public function filename(Project $project): string
    {
        return "project-{$project->id}-export.json";
    }
}
