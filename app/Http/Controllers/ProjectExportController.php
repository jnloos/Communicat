<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProjectExportController extends Controller
{
    public function json(Project $project): JsonResponse
    {
        abort_unless(Gate::allows('access-project', $project), 403);

        // schema_version 2: full clone payload (settings, expert memory, message
        // metadata) so a project can be reconstructed via ProjectImporter.
        $payload = [
            'schema_version' => 2,
            'project' => [
                'title'       => $project->title,
                'description' => $project->description,
                'settings'    => $project->settings ?? [],
                'created_at'  => optional($project->created_at)->toIso8601String(),
            ],
            'experts' => $project->contributingExperts()
                ->map(fn($e) => [
                    'id'          => $e->id,
                    'name'        => $e->name,
                    'job'         => $e->job,
                    'description' => $e->description,
                    'prompt'      => $e->prompt,
                    'voice_id'    => $e->voice_id,
                    'avatar_url'  => $e->avatar_url,
                    'tags'        => $e->tags->pluck('name')->all(),
                ])
                ->values()
                ->all(),
            'messages' => $project->messages()
                ->with(['expert:id,name', 'user:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn($m) => [
                    'id'                  => $m->id,
                    'content'             => $m->content,
                    'expert_id'           => $m->expert_id,
                    'is_user'             => $m->user_id !== null,
                    'sender_name'         => $m->expert?->name ?? $m->user?->name,
                    'adjacency_pair_type' => $m->adjacency_pair_type,
                    'next_speaker'        => $m->next_speaker,
                    'created_at'          => optional($m->created_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'summaries' => $project->summaries()
                ->get()
                ->map(fn($s) => [
                    'expert_id' => $s->expert_id,
                    'content'   => $s->content,
                ])
                ->values()
                ->all(),
        ];

        $filename = "project-{$project->id}-export.json";

        return response()->json($payload, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
