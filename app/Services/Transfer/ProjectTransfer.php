<?php

namespace App\Services\Transfer;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One run, complete: settings, frozen run_config, seats, messages, both memory
 * layers, the per-turn measurement and every logged call. The bundle's entry
 * point for a project file: ProjectExporter reads a run, ProjectImportCheck
 * decides whether a file can go in, ProjectWriter writes it.
 *
 * An expert whose id was taken in the target database is renumbered on import
 * (ExpertTransfer). expert_ids maps every original id to its uuid, so the ids
 * inside each turn's selection and in run_config.cast follow it
 * (ExpertRenumbering). Texts are never rewritten.
 */
final class ProjectTransfer
{
    public function __construct(
        private readonly ProjectExporter $exporter = new ProjectExporter,
        private readonly ProjectImportCheck $check = new ProjectImportCheck,
        private readonly ProjectWriter $writer = new ProjectWriter,
    ) {}

    /** @return list<int> */
    public function expertIds(Project $project): array
    {
        return $this->exporter->expertIds($project);
    }

    public function export(Project $project): array
    {
        return $this->exporter->export($project);
    }

    public function import(array $data, User $owner, ImportReport $report): ?Project
    {
        $title = EntryFields::label($data, 'title');

        try {
            ProjectFileShape::check($data);
        } catch (UnreadableEntry $exception) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::FAILED, FailureNote::for($exception));

            return null;
        }

        $reason = $this->check->reasonToSkip($data);

        if ($reason !== null) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::SKIPPED, $reason);

            return null;
        }

        $skippedTags = [];

        try {
            $project = DB::transaction(function () use ($data, $owner, &$skippedTags) {
                return $this->writer->write($data, $owner, $this->check->foundExperts($data), $skippedTags);
            });
        } catch (Throwable $exception) {
            $report->add(ImportReport::PROJECTS, $title, ImportReport::FAILED, FailureNote::for($exception));

            return null;
        }

        $report->add(
            ImportReport::PROJECTS,
            $title,
            ImportReport::CREATED,
            $skippedTags === [] ? null : __('transfer.notes.tags_skipped', ['tags' => implode(', ', $skippedTags)]),
        );

        return $project;
    }
}
