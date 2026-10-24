<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Employees, the base of the payroll. Owner and chief accountant. */
class EmployeeController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function index(): View
    {
        $this->authorize('view-employees');

        $employees = Employee::query()->orderByRaw("status = 'active' desc")->orderBy('full_name')->get();

        return view('settings.employees', [
            'employees' => $employees,
            'payrollTotal' => (int) $employees->where('status', 'active')->sum('salary_minor'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('view-employees');

        return view('settings.employee-form', ['employee' => new Employee(['status' => 'active', 'hired_on' => today()])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('view-employees');

        $employee = Employee::create($this->validated($request));
        $this->audit->record('employee.created', ['employee' => $employee->id, 'name' => $employee->full_name, 'salary_minor' => $employee->salary_minor]);

        return redirect()->route('settings.employees')->with('status', __('ui.employees.created'));
    }

    public function edit(Employee $employee): View
    {
        $this->authorize('view-employees');

        return view('settings.employee-form', compact('employee'));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('view-employees');

        $employee->update($this->validated($request, $employee));
        $this->audit->record('employee.updated', ['employee' => $employee->id, 'name' => $employee->full_name, 'changed' => implode(', ', array_keys($employee->getChanges()))]);

        return redirect()->route('settings.employees')->with('status', __('ui.employees.updated'));
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('view-employees');

        // Payroll lines keep the name; the employee is only soft-deleted.
        $employee->delete();
        $this->audit->record('employee.deleted', ['employee' => $employee->id, 'name' => $employee->full_name]);

        return redirect()->route('settings.employees')->with('status', __('ui.employees.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Employee $employee = null): array
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'pinfl' => ['nullable', 'regex:/^\d{14}$/'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'hired_on' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'on_leave', 'dismissed'])],
            'salary' => ['required', 'string', 'max:20'],
        ], ['pinfl.regex' => __('ui.employees.errors.pinfl')], collect(['full_name', 'pinfl', 'position', 'department', 'phone', 'hired_on', 'status', 'salary'])
            ->mapWithKeys(fn (string $f) => [$f => __('ui.employees.fields.'.$f)])->all());

        $salary = Money::parseToMinor($data['salary']);

        if ($salary === null) {
            throw ValidationException::withMessages(['salary' => __('ui.employees.errors.salary')]);
        }

        unset($data['salary']);

        return $data + ['salary_minor' => $salary];
    }
}
