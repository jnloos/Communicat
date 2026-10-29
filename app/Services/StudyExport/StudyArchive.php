<?php

namespace App\Services\StudyExport;

use RuntimeException;
use ZipArchive;

/**
 * The ZIP the study export ships: CSV files plus README.txt.
 *
 * The files are meant for R or Python, not for a spreadsheet. Text cells
 * (titles, reasoning, errors) are written verbatim: no Excel formula escaping,
 * because a prefixed quote would alter the data the analysis reads. README.txt
 * in the archive says so to whoever opens it.
 */
final class StudyArchive
{
    /** Shipped as README.txt, so whoever opens the ZIP reads how before a spreadsheet mangles a cell. */
    private const README = <<<'TXT'
        Communicat study export

        runs.csv          one row per run
        participants.csv  one row per agent of a run, joined to runs by run_uuid
        turns.csv         one row per turn, joined by run_uuid and expert_uuid

        participants.csv has one column per persona tag category and one per persona
        attribute (its value for that agent, empty when it has none; the name gets
        the prefix "attribute " when it matches another column). runs.csv has
        attribute_visibility: one cell like "Role=all; gender=own", per attribute
        none, own or all, what the run's prompts revealed of it (the setting frozen
        when the run started; empty when no attribute is defined).

        turns.csv, selector columns: fallback is 1 when the selector could not read
        its decision and drew the speaker instead (Orchestrator and the judges: the
        pick; Score: any agent's bid; the Jev selectors: the Jev request failed or
        its answer was unreadable; a Jev Score turn: any agent's Jev bid), empty for
        round robin. The Jev columns are filled only for the Jev pipelines and say
        two different things, so they are two columns. speaker_probability (0 to 1):
        the probability a Jev pick (Jev Orchestrator, Jev Quality, Jev Blind
        Quality) gave the agent who spoke; for the blind judge, the one it gave the
        speaker's letter. speaker_jev_score (1 to 5, unrounded): in Jev Score, the
        expected urgency level Jev gave the agent who spoke (speaker_bid holds the
        rounded bid the selector read). Both are empty when Jev gave nothing
        readable or was not asked (one candidate, or no draft handed in). A Jev run
        stops when Jev has not answered for several turns in a row (ai.jev.max_fallback_turns,
        every Jev request of each turn failed): the turn that stopped it is a row with
        spoken 0 and the draw it made, and nobody spoke in it.

        Read the files with R (readr::read_csv) or Python (pandas.read_csv): UTF-8,
        comma separated, quoted where needed. An empty cell means "not recorded",
        never 0.

        Spreadsheets: do not open the files with a double click. Excel reads a cell
        that starts with =, +, - or @ (some model reasoning does) as a formula and
        shows #NAME? or a wrong value. Import them instead (Data > From Text/CSV)
        and set the text columns to type Text.
        TXT;

    /**
     * @param  array<string, array{list<string>, list<array<string, scalar|null>>}>  $tables  file name => [columns, rows]
     */
    public function write(string $path, array $tables): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        foreach ($tables as $name => [$columns, $rows]) {
            $zip->addFromString($name, $this->csv($columns, $rows));
        }

        $zip->addFromString('README.txt', self::README."\n");

        if (! $zip->close()) {
            throw new RuntimeException("Could not finish the archive {$path}.");
        }
    }

    /**
     * NULL becomes an empty cell, never 0; a float is rounded to six places.
     *
     * @param  list<string>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     */
    private function csv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(function (string $column) use ($row) {
                $value = $row[$column] ?? null;

                return $value === null ? '' : (is_float($value) ? round($value, 6) : $value);
            }, $columns), escape: '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
