<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'legal_form', 'stir', 'address', 'phone', 'email', 'bank_name', 'bank_account', 'mfo',
    'director_name', 'chief_accountant_name', 'vat_payer', 'vat_registration_code', 'default_vat_rate_bp',
])]
class CompanySetting extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'vat_payer' => 'boolean',
        'is_demo' => 'boolean',
        'default_vat_rate_bp' => 'integer',
    ];
}
