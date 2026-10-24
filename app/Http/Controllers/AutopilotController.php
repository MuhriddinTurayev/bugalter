<?php

namespace App\Http\Controllers;

use App\Enums\AutopilotLevel;
use App\Enums\Permission;
use App\Enums\TaxReportType;
use App\Models\AiProposal;
use App\Models\Mandate;
use App\Models\SigningKey;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Services\Platform\KillSwitch;
use App\Submission\SubmissionOrchestrator;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Owner-only (autopilot.manage): autopilot level, cancel window, AI auto-apply, signing mode, mandates and the
 * company kill switch. The AI has no route to any of this.
 */
class AutopilotController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function show(CurrentCompany $company): View
    {
        $this->authorize(Permission::AutopilotManage->value);

        return view('autopilot.show', [
            'settings' => $company->settings(),
            'levels' => AutopilotLevel::cases(),
            'mandates' => Mandate::query()->latest('id')->get(),
            'hasMandate' => Mandate::hasActive(),
            'hasKey' => SigningKey::usable() !== null,
            'platformKillSwitch' => KillSwitch::isActive(),
            'reportTypes' => TaxReportType::cases(),
        ]);
    }

    public function update(Request $request, CurrentCompany $company): RedirectResponse
    {
        $this->authorize(Permission::AutopilotManage->value);

        $data = $request->validate([
            'autopilot_level' => ['required', Rule::enum(AutopilotLevel::class)],
            'cancel_window_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'signer_mode' => ['required', Rule::in(['vault', 'device'])],
            'auto_apply_classification' => ['boolean'],
            'auto_apply_min_confidence' => ['required', 'integer', 'min:50', 'max:100'],
            'portal_access' => ['boolean'],
        ], [], [
            'autopilot_level' => __('autopilot.level'),
            'cancel_window_hours' => __('autopilot.cancel_window'),
        ]);

        $level = AutopilotLevel::from((int) $data['autopilot_level']);

        // Levels 2 and 3 need an active mandate; level 3 with server signing also needs a key in the vault.
        if ($level->needsMandate() && ! Mandate::hasActive()) {
            throw ValidationException::withMessages(['autopilot_level' => __('autopilot.errors.mandate_required')]);
        }

        if ($level === AutopilotLevel::Auto && $data['signer_mode'] === 'vault' && SigningKey::usable() === null) {
            throw ValidationException::withMessages(['autopilot_level' => __('autopilot.errors.key_required')]);
        }

        $settings = $company->settings();
        $before = $settings->autopilot()->value;
        $portalAccess = $request->boolean('portal_access');

        // The owner's consent to sign in to the tax cabinet with the vault key; recorded with name and time.
        if ($portalAccess !== (bool) $settings->portal_access) {
            $settings->forceFill([
                'portal_access' => $portalAccess,
                'portal_access_by' => $portalAccess ? $request->user()->name : null,
                'portal_access_at' => $portalAccess ? now() : null,
            ]);
            $this->audit->record($portalAccess ? 'tax_portal.access_granted' : 'tax_portal.access_revoked');
        }

        $settings->forceFill([
            'autopilot_level' => $level,
            'cancel_window_hours' => (int) $data['cancel_window_hours'],
            'signer_mode' => $data['signer_mode'],
            'ai_auto_apply' => [AiProposal::CLASSIFY_TRANSACTION => [
                'enabled' => $request->boolean('auto_apply_classification'),
                'min_confidence' => (int) $data['auto_apply_min_confidence'],
            ]],
            // Choosing a level again is the way back after the kill switch.
            'autopilot_paused_at' => $level === AutopilotLevel::Off ? $settings->autopilot_paused_at : null,
            'autopilot_paused_by' => $level === AutopilotLevel::Off ? $settings->autopilot_paused_by : null,
        ])->save();

        $this->audit->record('autopilot.settings_updated', [
            'level_from' => $before,
            'level_to' => $level->value,
            'cancel_window_hours' => (int) $data['cancel_window_hours'],
            'signer_mode' => $data['signer_mode'],
            'auto_apply' => $request->boolean('auto_apply_classification') ? $data['auto_apply_min_confidence'].'%' : 'off',
        ]);

        return back()->with('status', __('autopilot.saved'));
    }

    public function storeMandate(Request $request, CurrentCompany $company): RedirectResponse
    {
        $this->authorize(Permission::AutopilotManage->value);

        $data = $request->validate([
            'report_types' => ['required', 'array', 'min:1'],
            'report_types.*' => [Rule::enum(TaxReportType::class)],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'amount_limit' => ['required', 'string', 'max:20'],
            'change_limit_percent' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'expires_at' => ['required', 'date', 'after:today'],
            'signed_name' => ['required', 'string', 'max:255'],
            'confirm' => ['accepted'],
        ], [], collect(['report_types', 'period_from', 'period_to', 'amount_limit', 'change_limit_percent', 'expires_at', 'signed_name', 'confirm'])
            ->mapWithKeys(fn (string $f) => [$f => __('autopilot.mandate.fields.'.$f)])->all());

        $limit = Money::parseToMinor($data['amount_limit']) ?? throw ValidationException::withMessages(['amount_limit' => __('autopilot.errors.amount')]);

        // The written authorisation is signed with the owner's full name, as on file.
        if (mb_strtolower(trim($data['signed_name'])) !== mb_strtolower(trim($request->user()->name))) {
            throw ValidationException::withMessages(['signed_name' => __('autopilot.errors.signed_name', ['name' => $request->user()->name])]);
        }

        $mandate = Mandate::create([
            'report_types' => array_values(array_unique($data['report_types'])),
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'amount_limit_minor' => $limit,
            'change_limit_percent' => $data['change_limit_percent'] ?? null,
            'expires_at' => $data['expires_at'],
            'status' => 'active',
            'signed_name' => $data['signed_name'],
            'signed_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record('mandate.created', [
            'mandate' => $mandate->id,
            'types' => implode(', ', $mandate->report_types),
            'amount_limit_minor' => $limit,
            'expires_at' => $mandate->expires_at->toDateString(),
        ], reason: __('autopilot.mandate.signed_as', ['name' => $mandate->signed_name]));

        return back()->with('status', __('autopilot.mandate.created'));
    }

    public function revokeMandate(Request $request, Mandate $mandate, CurrentCompany $company): RedirectResponse
    {
        $this->authorize(Permission::AutopilotManage->value);

        $mandate->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_name' => $request->user()->name]);
        $this->audit->record('mandate.revoked', ['mandate' => $mandate->id]);

        // Without a mandate, levels 2 and 3 are not allowed: fall back to level 1.
        $settings = $company->settings();
        if ($settings->autopilot()->needsMandate() && ! Mandate::hasActive()) {
            $settings->forceFill(['autopilot_level' => AutopilotLevel::Prepare])->save();
            $this->audit->record('autopilot.level_lowered', ['to' => 1], reason: __('autopilot.notes.mandate_revoked'));
        }

        return back()->with('status', __('autopilot.mandate.revoked'));
    }

    public function killSwitch(Request $request, SubmissionOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize(Permission::AutopilotManage->value);

        $cancelled = $orchestrator->killSwitch($request->user());

        return back()->with('status', __('autopilot.kill_switch_done', ['count' => $cancelled]));
    }
}
