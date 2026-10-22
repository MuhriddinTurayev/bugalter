<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['full_name', 'pinfl', 'position', 'department', 'phone', 'hired_on', 'status', 'salary_minor'])]
class Employee extends Model
{
    use SoftDeletes;

    /** @var array<string, string> */
    protected $casts = [
        'hired_on' => 'date',
        'salary_minor' => 'integer',
    ];

    public function maskedPinfl(): string
    {
        if ($this->pinfl === null || strlen($this->pinfl) < 4) {
            return '—';
        }

        return str_repeat('•', strlen($this->pinfl) - 4).substr($this->pinfl, -4);
    }
}
