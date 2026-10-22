<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanySettingRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SettingsController extends Controller
{
    public function company(CurrentCompany $company): View
    {
        $this->authorize('manage-settings');

        return view('settings.company', ['company' => $company->settings()]);
    }

    public function updateCompany(CompanySettingRequest $request, CurrentCompany $company): RedirectResponse
    {
        $settings = $company->settings();
        $settings->fill($request->companyData())->save();

        return redirect()->route('settings.company')->with('status', __('ui.settings.saved'));
    }

    public function users(): View
    {
        $this->authorize('manage-users');

        return view('settings.users', ['users' => User::query()->orderBy('name')->get()]);
    }

    /** The company's own audit log; it includes every platform support access to this company. */
    public function audit(): View
    {
        $this->authorize('manage-users');

        return view('settings.audit', ['logs' => AuditLog::query()->latest('id')->paginate(25)]);
    }

    public function employees(): View
    {
        $this->authorize('view-employees');

        return view('settings.employees', ['employees' => Employee::query()->orderBy('full_name')->get()]);
    }
}
