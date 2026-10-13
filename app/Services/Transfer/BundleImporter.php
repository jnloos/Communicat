<?php

namespace App\Services\Transfer;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads a bundle in a fixed order -- tags, experts, projects, groups, questions --
 * so every part finds what it refers to. The preview runs the same import in a
 * transaction that is rolled back: what it reports is what an import would do.
 */
final class BundleImporter
{
    public function __construct(
        private readonly TagTransfer $tags,
        private readonly ExpertTransfer $experts,
        private readonly ProjectTransfer $projects,
        private readonly GroupTransfer $groups,
        private readonly QuestionTransfer $questions,
    ) {}

    public function import(string $path, User $owner): ImportReport
    {
        return $this->read(BundleReader::open($path), $owner);
    }

    public function preview(string $path, User $owner): ImportReport
    {
        $reader = BundleReader::open($path);
        DB::beginTransaction();

        try {
            return $this->read($reader, $owner);
        } finally {
            DB::rollBack();
        }
    }

    private function read(BundleReader $reader, User $owner): ImportReport
    {
        $report = new ImportReport;
        $this->tags->import($reader->json(Bundle::TAGS), $report);

        foreach ($reader->json(Bundle::EXPERTS) as $expert) {
            $this->experts->import($expert, $report);
        }

        foreach ($reader->projectEntries() as $entry) {
            // A broken project file fails alone; ProjectTransfer already rolls back a failed write.
            try {
                $this->projects->import($reader->json($entry), $owner, $report);
            } catch (Throwable $exception) {
                $report->add(ImportReport::PROJECTS, $entry, ImportReport::FAILED, $exception->getMessage());
            }
        }

        $this->groups->import($reader->json(Bundle::GROUPS), $owner, $report);
        $this->questions->import($reader->json(Bundle::QUESTIONS), $owner, $report);

        return $report;
    }
}
