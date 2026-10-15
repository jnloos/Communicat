<?php

namespace App\Console;

/** How `project:metrics` writes its numbers: for a reader, or for a script. */
enum MetricsFormat: string
{
    case Table = 'table';
    case Json = 'json';
    case Csv = 'csv';

    /** "table, json or csv", for the option's help and its error message. */
    public static function listed(): string
    {
        $values = array_map(fn (self $format) => $format->value, self::cases());

        return implode(', ', array_slice($values, 0, -1)).' or '.end($values);
    }
}
