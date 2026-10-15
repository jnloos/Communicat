<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DownloadsTemporaryFile;
use App\Models\Expert;
use App\Models\Project;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleExporter;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BundleExportController extends Controller
{
    use DownloadsTemporaryFile;

    public function __construct(private readonly BundleExporter $exporter) {}

    public function project(Project $project): BinaryFileResponse
    {
        Gate::authorize('access-project', $project);

        return $this->downloadTemporary(fn (string $path) => $this->exporter->project($project, $path), $this->exporter->filename(Bundle::KIND_PROJECT, $project->title));
    }

    public function expert(Expert $expert): BinaryFileResponse
    {
        return $this->downloadTemporary(fn (string $path) => $this->exporter->expert($expert, $path), $this->exporter->filename(Bundle::KIND_EXPERT, $expert->name));
    }

    public function everything(): BinaryFileResponse
    {
        Gate::authorize('admin');

        return $this->downloadTemporary(fn (string $path) => $this->exporter->everything(auth()->user(), $path), $this->exporter->filename(Bundle::KIND_GLOBAL, 'all'));
    }
}
