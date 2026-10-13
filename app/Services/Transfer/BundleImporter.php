<?php

namespace App\Services\Transfer;

use App\Models\User;
use Closure;
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

        $this->section($report, ImportReport::TAGS, Bundle::TAGS, fn () => $this->tags->import($reader->json(Bundle::TAGS), $report));
        $this->section($report, ImportReport::EXPERTS, Bundle::EXPERTS, fn () => $this->experts->importAll($reader->json(Bundle::EXPERTS), $report, withRuns: $reader->projectEntries() !== []));

        foreach ($reader->projectEntries() as $entry) {
            // ProjectTransfer catches and rolls back a failure of its own write.
            // What reaches this catch is a project file that cannot be read
            // (InvalidBundle from json()) or whose fields have the wrong type
            // before the write starts; that file fails alone.
            $this->section($report, ImportReport::PROJECTS, $entry, fn () => $this->projects->import($reader->json($entry), $owner, $report));
        }

        $this->section($report, ImportReport::GROUPS, Bundle::GROUPS, fn () => $this->groups->import($reader->json(Bundle::GROUPS), $owner, $report));
        $this->section($report, ImportReport::QUESTIONS, Bundle::QUESTIONS, fn () => $this->questions->import($reader->json(Bundle::QUESTIONS), $owner, $report));

        return $report;
    }

    /**
     * One file of the bundle; when it fails as a whole (it cannot be read,
     * say), it is reported as one failed entry under its file name and the
     * rest of the bundle still goes in, so the report of what was written
     * survives. Entries it wrote before failing stay written and reported.
     * The note is the reason for an unreadable file, a generic text for
     * anything else (FailureNote), which is logged.
     */
    private function section(ImportReport $report, string $section, string $file, Closure $import): void
    {
        try {
            $import();
        } catch (Throwable $exception) {
            $report->add($section, $file, ImportReport::FAILED, FailureNote::for($exception));
        }
    }
}
