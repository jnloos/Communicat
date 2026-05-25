<?php

namespace App\Services\ProjectTransfer;

use App\Models\Project;

class ProjectExport
{
    /** Current export schema version (consumed by ProjectImporter). */
    public const SCHEMA_VERSION = 3;

    /**
     * Build the full clone payload for a project: settings, contributing experts,
     * all messages with metadata, and per-expert memory (summaries). The shape is
     * the contract consumed by {@see ProjectImporter::import()}.
     */
    public function toArray(Project $project): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'project' => [
                'title'       => $project->title,
                'description' => $project->description,
                'settings'    => $project->settings ?? [],
                'created_at'  => optional($project->created_at)->toIso8601String(),
            ],
            'experts' => $project->contributingExperts()
                ->map(fn ($e) => [
                    'id'               => $e->id,
                    'name'             => $e->name,
                    'job'              => $e->job,
                    'description'      => $e->description,
                    'profile'          => $e->profile,
                    'core_beliefs'     => $e->core_beliefs,
                    'knowledge_limits' => $e->knowledge_limits,
                    'style'            => $e->style,
                    'voice_id'         => $e->voice_id,
                    'avatar_url'       => $e->avatar_url,
                    'tags'             => $e->tags->pluck('name')->all(),
                ])
                ->values()
                ->all(),
            'messages' => $project->messages()
                ->with(['expert:id,name', 'user:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => [
                    'id'                  => $m->id,
                    'content'             => $m->content,
                    'expert_id'           => $m->expert_id,
                    'is_user'             => $m->user_id !== null,
                    'sender_name'         => $m->expert?->name ?? $m->user?->name,
                    'adjacency_pair_type'    => $m->adjacency_pair_type,
                    'next_speaker_expert_id' => $m->next_speaker_expert_id,
                    'next_speaker_user_id'   => $m->next_speaker_user_id,
                    'created_at'             => optional($m->created_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'summaries' => $project->summaries()
                ->get()
                ->map(fn ($s) => [
                    'expert_id' => $s->expert_id,
                    'content'   => $s->content,
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
