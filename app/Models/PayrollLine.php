<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payroll_run_id', 'employee_id', 'employee_name', 'position', 'gross_minor', 'pit_minor', 'inps_minor', 'social_minor', 'net_minor'])]
class PayrollLine extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'gross_minor' => 'integer',
        'pit_minor' => 'integer',
        'inps_minor' => 'integer',
        'social_minor' => 'integer',
        'net_minor' => 'integer',
    ];

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
