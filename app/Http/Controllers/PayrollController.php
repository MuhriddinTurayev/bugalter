<?php

namespace App\Http\Controllers;

use App\Enums\Actor;
use App\Models\Account;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Payroll\PayrollService;
use App\Submission\SubmissionRefused;
use App\Support\Money;
use App\Tax\MissingTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(): View
    {
        $this->authorize('view-employees');

        return view('payroll.index', [
            'runs' => PayrollRun::query()->latest('period')->paginate(12),
            'suggestedMonth' => now()->startOfMonth()->subMonth()->format('Y-m'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('view-employees');

        $data = $request->validate(['month' => ['required', 'date_format:Y-m']], [], ['month' => __('payroll.month')]);

        try {
            $run = $this->payroll->prepare(CarbonImmutable::createFromFormat('!Y-m', $data['month']), Actor::User, $request->user()->name);
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        } catch (MissingTaxRule $e) {
            return back()->with('error', __('tax.errors.missing_rule', ['code' => $e->ruleCode]));
        }

        return redirect()->route('payroll.show', $run)->with('status', __('payroll.prepared'));
    }

    public function show(PayrollRun $run): View
    {
        $this->authorize('view-employees');

        return view('payroll.show', [
            'run' => $run->load('lines.employee', 'approver'),
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateLine(Request $request, PayrollRun $run, PayrollLine $line): RedirectResponse
    {
        $this->authorize('view-employees');
        abort_unless($line->payroll_run_id === $run->id, 404);

        $data = $request->validate(['gross' => ['required', 'string', 'max:20']]);
        $gross = Money::parseToMinor($data['gross']) ?? throw ValidationException::withMessages(['gross' => __('ui.employees.errors.salary')]);

        return $this->attempt(fn () => $this->payroll->updateLine($line, $gross), 'payroll.line_updated');
    }

    public function approve(Request $request, PayrollRun $run): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->approve($run, $request->user()), 'payroll.approved');
    }

    public function reopen(Request $request, PayrollRun $run): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->reopen($run, $request->user()), 'payroll.reopened');
    }

    public function pay(Request $request, PayrollRun $run): RedirectResponse
    {
        $data = $request->validate(['account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)]], [], ['account_id' => __('ui.bank.fields.account')]);

        return $this->attempt(fn () => $this->payroll->markPaid($run, Account::query()->findOrFail($data['account_id']), $request->user()), 'payroll.paid');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $run = $action();
        } catch (SubmissionRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.show', $run)->with('status', __($message));
    }
}
