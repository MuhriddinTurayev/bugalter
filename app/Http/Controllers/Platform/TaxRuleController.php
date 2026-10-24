<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\TaxDeadline;
use App\Models\TaxRule;
use App\Services\Platform\PlatformAudit;
use App\Support\PlatformUrl;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\TaxRuleSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The tax rules every company is calculated with. A super admin corrects a value (which makes it a new,
 * unverified version) and marks a rule verified after checking it against the law in force.
 */
class TaxRuleController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): View
    {
        return view('platform.tax-rules', [
            'rules' => TaxRule::query()->orderBy('code')->orderByDesc('effective_from')->get(),
            'deadlines' => TaxDeadline::query()->orderBy('report_type')->orderByDesc('effective_from')->get(),
        ]);
    }

    public function updateRule(Request $request, TaxRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:30'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:'.$rule->effective_from->toDateString()],
            'source' => ['nullable', 'string', 'max:1000'],
        ]);

        $value = $this->parseValue($rule->kind, $data['value']);
        $changed = $value !== $rule->value;

        $rule->fill(['value' => $value, 'effective_to' => $data['effective_to'] ?? null, 'source' => $data['source'] ?? $rule->source]);

        if ($changed) {
            $rule->fill(['status' => TaxRule::NEEDS_VERIFICATION, 'verified_by' => null, 'verified_at' => null, 'version' => $rule->version + 1]);
        }

        $rule->save();
        $this->audit->record('tax_rule.updated', null, null, ['code' => $rule->code, 'value' => $value, 'version' => $rule->version]);

        return redirect(PlatformUrl::route('tax-rules'))->with('status', __('platform.tax_rules.saved', ['code' => $rule->code]));
    }

    public function verifyRule(Request $request, TaxRule $rule): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $rule->update(['status' => TaxRule::VERIFIED, 'verified_by' => $staff->name, 'verified_at' => now()]);
        $this->audit->record('tax_rule.verified', null, null, ['code' => $rule->code, 'value' => $rule->value, 'version' => $rule->version]);

        return back()->with('status', __('platform.tax_rules.verified', ['code' => $rule->code]));
    }

    public function updateDeadline(Request $request, TaxDeadline $deadline): RedirectResponse
    {
        $data = $request->validate([
            'months_after' => ['required', 'integer', 'min:0', 'max:12'],
            'day' => ['required', 'integer', 'min:1', 'max:31'],
            'source' => ['nullable', 'string', 'max:1000'],
        ]);

        $changed = (int) $data['months_after'] !== $deadline->months_after || (int) $data['day'] !== $deadline->day;
        $deadline->fill(['months_after' => (int) $data['months_after'], 'day' => (int) $data['day'], 'source' => $data['source'] ?? $deadline->source]);

        if ($changed) {
            $deadline->fill(['status' => TaxRule::NEEDS_VERIFICATION, 'verified_by' => null, 'verified_at' => null, 'version' => $deadline->version + 1]);
        }

        $deadline->save();
        $this->audit->record('tax_deadline.updated', null, null, ['type' => $deadline->report_type, 'months_after' => $deadline->months_after, 'day' => $deadline->day]);

        return redirect(PlatformUrl::route('tax-rules'))->with('status', __('platform.tax_rules.deadline_saved'));
    }

    public function verifyDeadline(Request $request, TaxDeadline $deadline): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');

        $deadline->update(['status' => TaxRule::VERIFIED, 'verified_by' => $staff->name, 'verified_at' => now()]);
        $this->audit->record('tax_deadline.verified', null, null, ['type' => $deadline->report_type, 'version' => $deadline->version]);

        return back()->with('status', __('platform.tax_rules.deadline_verified'));
    }

    /** Marks every unverified rule and deadline verified in one step, one audit entry per row. */
    public function verifyAll(Request $request): RedirectResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->user('platform');
        $stamp = ['status' => TaxRule::VERIFIED, 'verified_by' => $staff->name, 'verified_at' => now()];
        $count = 0;

        foreach (TaxRule::query()->where('status', '!=', TaxRule::VERIFIED)->get() as $rule) {
            $rule->update($stamp);
            $this->audit->record('tax_rule.verified', null, null, ['code' => $rule->code, 'value' => $rule->value, 'version' => $rule->version]);
            $count++;
        }

        foreach (TaxDeadline::query()->where('status', '!=', TaxRule::VERIFIED)->get() as $deadline) {
            $deadline->update($stamp);
            $this->audit->record('tax_deadline.verified', null, null, ['type' => $deadline->report_type, 'version' => $deadline->version]);
            $count++;
        }

        return back()->with('status', __('platform.tax_rules.all_verified', ['count' => $count]));
    }

    /** Reloads docs/tax/rules.json. Unchanged verified rows stay verified. */
    public function sync(TaxRuleSeeder $seeder): RedirectResponse
    {
        $result = $seeder->sync();
        $this->audit->record('tax_rule.synced', null, null, $result);

        return back()->with('status', __('platform.tax_rules.synced', ['changed' => $result['changed']]));
    }

    /** "12" or "12,5" percent → basis points; an amount in soʻm → tiyin. */
    private function parseValue(string $kind, string $input): int
    {
        $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($input));

        if (! preg_match('/^\d{1,15}(\.\d{1,2})?$/', $clean)) {
            throw ValidationException::withMessages(['value' => __('platform.tax_rules.invalid_value')]);
        }

        return BigDecimal::of($clean)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
