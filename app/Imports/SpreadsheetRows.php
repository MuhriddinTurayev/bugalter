<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/** Reads every sheet row as a plain array; validation and persistence are done by the import action. */
class SpreadsheetRows implements ToArray
{
    public function array(array $array): void
    {
        // Rows are collected by Excel::toArray(); nothing to do here.
    }
}
