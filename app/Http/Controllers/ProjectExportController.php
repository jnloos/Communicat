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

        $payload = [
            'project' => [
                'id'          => $project->id,
                'title'       => $project->title,
                'description' => $project->description,
                'created_at'  => optional($project->created_at)->toIso8601String(),
            ],
            'experts' => $project->contributingExperts()
                ->map(fn($e) => [
                    'id'          => $e->id,
                    'name'        => $e->name,
                    'job'         => $e->job,
                    'description' => $e->description,
                ])
                ->values()
                ->all(),
            'messages' => $project->messages()
                ->with(['expert:id,name', 'user:id,name'])
                ->orderBy('id')
                ->get()
                ->map(function ($m) {
                    $senderType = $m->expert_id ? 'expert' : ($m->user_id ? 'user' : 'system');
                    $senderName = $m->expert?->name ?? $m->user?->name;
                    return [
                        'id'           => $m->id,
                        'content'      => $m->content,
                        'sender_type'  => $senderType,
                        'sender_name'  => $senderName,
                        'created_at'   => optional($m->created_at)->toIso8601String(),
                        'next_speaker' => $m->next_speaker,
                    ];
                })
                ->values()
                ->all(),
        ];

        $filename = "project-{$project->id}-export.json";

        return response()->json($payload, 200, [
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
