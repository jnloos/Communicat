<?php

namespace Tests\Unit\Transfer;

use App\Services\Transfer\ImportReport;
use PHPUnit\Framework\TestCase;

class ImportReportTest extends TestCase
{
    public function test_it_counts_per_section_and_outcome_and_survives_an_array_round_trip(): void
    {
        $report = new ImportReport;
        $report->add(ImportReport::PROJECTS, 'School', ImportReport::CREATED);
        $report->add(ImportReport::PROJECTS, 'Office', ImportReport::SKIPPED, 'already there');
        $report->add(ImportReport::EXPERTS, 'Alex', ImportReport::FAILED, 'broken');

        $copy = ImportReport::fromArray($report->toArray());

        $this->assertSame([ImportReport::EXPERTS, ImportReport::PROJECTS], $copy->sections());
        $this->assertSame(1, $copy->count(ImportReport::PROJECTS, ImportReport::SKIPPED));
        $this->assertSame('already there', $copy->entries(ImportReport::PROJECTS)[1]['note']);
        $this->assertTrue($copy->hasFailures());
    }

    public function test_failed_entries_come_first_and_each_group_keeps_its_order(): void
    {
        $report = new ImportReport;
        $report->add(ImportReport::PROJECTS, 'A', ImportReport::CREATED);
        $report->add(ImportReport::PROJECTS, 'B', ImportReport::FAILED);
        $report->add(ImportReport::PROJECTS, 'C', ImportReport::SKIPPED);
        $report->add(ImportReport::PROJECTS, 'D', ImportReport::FAILED);

        $names = array_column($report->entriesFailedFirst(ImportReport::PROJECTS), 'name');

        $this->assertSame(['B', 'D', 'A', 'C'], $names);
        $this->assertSame([], $report->entriesFailedFirst(ImportReport::TAGS));
    }
}
