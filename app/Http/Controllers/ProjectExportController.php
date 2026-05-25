<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectTransfer\ProjectExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProjectExportController extends Controller
{
    public function json(Project $project, ProjectExport $export): JsonResponse
    {
        abort_unless(Gate::allows('access-project', $project), 403);

        return response()->json($export->toArray($project), 200, [
            'Content-Disposition' => "attachment; filename=\"{$export->filename($project)}\"",
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
