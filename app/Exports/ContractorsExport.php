<?php

namespace App\Exports;

use App\Models\Contractor;
use App\Support\ContractorColumns;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContractorsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Contractor::query()->orderBy('name');
    }

    public function headings(): array
    {
        return ContractorColumns::headings();
    }

    /** @param  Contractor  $row */
    public function map($row): array
    {
        return [
            $row->name,
            $row->stir,
            $row->type->value,
            $row->phone,
            $row->email,
            $row->address,
            $row->bank_name,
            $row->bank_account,
            $row->mfo,
        ];
    }
}
