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
        attribute_visibility: none, own or all, what the run's prompts revealed.

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
