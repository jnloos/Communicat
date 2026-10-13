<?php

namespace App\Http\Controllers;

use App\Models\Expert;
use App\Models\Project;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleExporter;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BundleExportController extends Controller
{
    public function __construct(private readonly BundleExporter $exporter) {}

    public function project(Project $project): BinaryFileResponse
    {
        abort_unless(Gate::allows('access-project', $project), 403);

        return $this->download(fn (string $path) => $this->exporter->project($project, $path), $this->exporter->filename(Bundle::KIND_PROJECT, $project->title));
    }

    public function expert(Expert $expert): BinaryFileResponse
    {
        return $this->download(fn (string $path) => $this->exporter->expert($expert, $path), $this->exporter->filename(Bundle::KIND_EXPERT, $expert->name));
    }

    public function everything(): BinaryFileResponse
    {
        Gate::authorize('admin');

        return $this->download(fn (string $path) => $this->exporter->everything(auth()->user(), $path), $this->exporter->filename(Bundle::KIND_GLOBAL, 'all'));
    }

    private function download(callable $write, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'bundle');

        try {
            $write($path);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
