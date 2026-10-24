<?php

namespace App\Payroll;

use App\Enums\Actor;
use App\Enums\PayrollStatus;
use App\Enums\Permission;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Submission\SubmissionRefused;
use App\Tax\Calculators\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Monthly payroll: prepared by a person or the AI/autopilot, approved by someone with payroll.approve. */
class PayrollService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly CurrentCompany $company,
        private readonly Audit $audit,
        private readonly CompanyNotifier $notifier,
    ) {}

    /** Creates the month's draft from active employees, or refreshes an existing draft. */
    public function prepare(CarbonImmutable $month, Actor $actor = Actor::User, ?string $actorName = null): PayrollRun
    {
        $period = $month->startOfMonth();
        $end = $period->endOfMonth();

        return DB::transaction(function () use ($period, $end, $actor, $actorName) {
            $run = PayrollRun::query()->whereDate('period', $period->toDateString())->lockForUpdate()->first();

            if ($run !== null && ! $run->isEditable()) {
                throw new SubmissionRefused(__('payroll.errors.not_draft'));
            }

            $run ??= PayrollRun::create(['period' => $period->toDateString(), 'status' => PayrollStatus::Draft]);
            $run->lines()->delete();

            $employees = Employee::query()
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('hired_on')->orWhereDate('hired_on', '<=', $end->toDateString()))
                ->orderBy('full_name')
                ->get();

            foreach ($employees as $employee) {
                $this->writeLine($run, $employee->salary_minor, $end, [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name,
                    'position' => $employee->position,
                ]);
            }

            $this->refreshTotals($run, $end, $actor, $actorName);
            $this->audit->record('payroll.prepared', ['payroll' => $run->id, 'period' => $period->format('Y-m'), 'employees' => $employees->count(), 'gross_minor' => $run->gross_minor], $actor, null, $actorName);

            return $run->fresh('lines');
        });
    }

    /** Changes one employee's gross pay in a draft (bonus, unpaid leave). */
    public function updateLine(PayrollLine $line, int $grossMinor): PayrollRun
    {
        $run = $line->run;

        if (! $run->isEditable()) {
            throw new SubmissionRefused(__('payroll.errors.not_draft'));
        }

        $end = $run->period->toImmutable()->endOfMonth();
        $values = $this->calculator->line($grossMinor, $end, $this->budget());
        $line->update([
            'gross_minor' => $values['gross'],
            'pit_minor' => $values['pit'],
            'inps_minor' => $values['inps'],
            'social_minor' => $values['social'],
            'net_minor' => $values['net'],
        ]);

        $this->refreshTotals($run, $end, Actor::User, null);
        $this->audit->record('payroll.line_changed', ['payroll' => $run->id, 'employee' => $line->employee_name, 'gross_minor' => $grossMinor]);

        return $run->fresh('lines');
    }

    public function approve(PayrollRun $run, User $user): PayrollRun
    {
        if (! $user->hasPermission(Permission::PayrollApprove)) {
            throw new SubmissionRefused(__('payroll.errors.no_permission'));
        }

        if (! $run->isEditable() || $run->lines()->count() === 0) {
            throw new SubmissionRefused(__('payroll.errors.cannot_approve'));
        }

        $run->update(['status' => PayrollStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()]);
        $this->audit->record('payroll.approved', ['payroll' => $run->id, 'gross_minor' => $run->gross_minor, 'net_minor' => $run->net_minor]);

        return $run;
    }

    public function reopen(PayrollRun $run, User $user): PayrollRun
    {
        if (! $user->hasPermission(Permission::PayrollApprove) || $run->status !== PayrollStatus::Approved) {
            throw new SubmissionRefused(__('payroll.errors.cannot_reopen'));
        }

        $run->update(['status' => PayrollStatus::Draft, 'approved_by' => null, 'approved_at' => null]);
        $this->audit->record('payroll.reopened', ['payroll' => $run->id]);

        return $run;
    }

    /** Books the net pay as one salary payment from the chosen account. */
    public function markPaid(PayrollRun $run, Account $account, User $user): PayrollRun
    {
        if (! $user->hasPermission(Permission::PayrollApprove) || $run->status !== PayrollStatus::Approved) {
            throw new SubmissionRefused(__('payroll.errors.cannot_pay'));
        }

        return DB::transaction(function () use ($run, $account, $user) {
            $category = Category::query()->where('key', 'salaries')->first()
                ?? Category::create(['key' => 'salaries', 'name' => __('ui.categories.salaries'), 'type' => TransactionType::Expense]);

            $transaction = Transaction::create([
                'account_id' => $account->id,
                'category_id' => $category->id,
                'type' => TransactionType::Expense,
                'amount_minor' => $run->net_minor,
                'occurred_at' => now(),
                'description' => __('payroll.payment_description', ['period' => $run->periodLabel()]),
                'status' => 'completed',
                'created_by' => $user->id,
            ]);

            $run->update(['status' => PayrollStatus::Paid, 'paid_transaction_id' => $transaction->id, 'paid_at' => now()]);
            $this->audit->record('payroll.paid', ['payroll' => $run->id, 'transaction' => $transaction->id, 'net_minor' => $run->net_minor]);
            $this->notifier->managers('payroll.notifications.paid', ['period' => $run->periodLabel()], route('payroll.show', $run, false));

            return $run;
        });
    }

    private function writeLine(PayrollRun $run, int $grossMinor, CarbonImmutable $on, array $attributes): void
    {
        $values = $this->calculator->line($grossMinor, $on, $this->budget());

        $run->lines()->create($attributes + [
            'gross_minor' => $values['gross'],
            'pit_minor' => $values['pit'],
            'inps_minor' => $values['inps'],
            'social_minor' => $values['social'],
            'net_minor' => $values['net'],
        ]);
    }

    private function refreshTotals(PayrollRun $run, CarbonImmutable $on, Actor $actor, ?string $actorName): void
    {
        // A plain aggregate row (not a model), without the relation's ordering.
        $sums = $run->lines()->reorder()->toBase()
            ->selectRaw('COALESCE(SUM(gross_minor),0) g, COALESCE(SUM(pit_minor),0) p, COALESCE(SUM(inps_minor),0) i, COALESCE(SUM(social_minor),0) s, COALESCE(SUM(net_minor),0) n')
            ->first();

        $run->update([
            'gross_minor' => (int) $sums->g,
            'pit_minor' => (int) $sums->p,
            'inps_minor' => (int) $sums->i,
            'social_minor' => (int) $sums->s,
            'net_minor' => (int) $sums->n,
            'rules' => array_map(fn ($rule) => $rule->reference(), $this->calculator->rules($on, $this->budget())),
            'prepared_actor' => $actor->value,
            'prepared_by_name' => $actorName ?? ($actor === Actor::User ? auth()->user()?->name : $actor->label()),
        ]);
    }

    private function budget(): bool
    {
        return (bool) $this->company->settings()->is_budget_org;
    }
}
