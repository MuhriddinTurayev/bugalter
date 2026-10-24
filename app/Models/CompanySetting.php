<?php

namespace App\Models;

use App\Enums\AutopilotLevel;
use App\Enums\TaxRegime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'legal_form', 'stir', 'pinfl', 'address', 'phone', 'email', 'bank_name', 'bank_account', 'mfo',
    'director_name', 'chief_accountant_name', 'vat_payer', 'vat_registration_code', 'default_vat_rate_bp',
    'tax_regime', 'is_budget_org', 'tax_region', 'tax_district',
])]
#[Hidden(['ai_api_key'])]
class CompanySetting extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'vat_payer' => 'boolean',
        'is_demo' => 'boolean',
        'default_vat_rate_bp' => 'integer',
        'tax_regime' => TaxRegime::class,
        'is_budget_org' => 'boolean',
        'autopilot_level' => AutopilotLevel::class,
        'cancel_window_hours' => 'integer',
        'autopilot_paused_at' => 'datetime',
        'ai_api_key' => 'encrypted',
        'ai_auto_apply' => 'array',
        'portal_access' => 'boolean',
        'portal_access_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tax_regime' => 'general',
        'autopilot_level' => 0,
        'cancel_window_hours' => 24,
        'signer_mode' => 'vault',
        'portal_access' => false,
    ];

    public function regime(): TaxRegime
    {
        return $this->tax_regime ?? TaxRegime::General;
    }

    public function autopilot(): AutopilotLevel
    {
        return $this->autopilot_level ?? AutopilotLevel::Off;
    }

    /** The owner's company-level kill switch (PLAN section 5, step 7). */
    public function isAutopilotPaused(): bool
    {
        return $this->autopilot_paused_at !== null;
    }

    /** Auto-apply setting of the AI for one action type, e.g. classify_transaction. */
    public function autoApply(string $action): array
    {
        return array_merge(['enabled' => false, 'min_confidence' => 90], $this->ai_auto_apply[$action] ?? []);
    }
}
