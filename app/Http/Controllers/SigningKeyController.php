<?php

namespace App\Http\Controllers;

use App\Enums\AutopilotLevel;
use App\Enums\Permission;
use App\Models\SignatureLog;
use App\Models\SigningKey;
use App\Services\Audit;
use App\Services\CurrentCompany;
use App\Submission\Signing\KeyVault;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ERI/E-imzo keys (signing.keys.manage, owner by default). Uploaded straight into the encrypted vault; the key
 * and its password are never shown, logged or sent back to the browser.
 */
class SigningKeyController extends Controller
{
    public function index(): View
    {
        $this->authorize(Permission::SigningKeysManage->value);

        return view('autopilot.keys', [
            'keys' => SigningKey::query()->latest('id')->get(),
            'signatures' => SignatureLog::query()->with(['report', 'key'])->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, KeyVault $vault, Audit $audit): RedirectResponse
    {
        $this->authorize(Permission::SigningKeysManage->value);

        $data = $request->validate([
            'key_file' => ['required', 'file', 'max:'.config('signing.max_key_kb'), 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:200'],
            'label' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_tin' => ['nullable', 'regex:/^(\d{9}|\d{14})$/'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['required', 'date', 'after:today'],
        ], [], collect(['key_file', 'password', 'label', 'owner_name', 'owner_tin', 'serial_number', 'valid_from', 'valid_to'])
            ->mapWithKeys(fn (string $f) => [$f => __('autopilot.keys.fields.'.$f)])->all());

        $key = $vault->store((string) file_get_contents($request->file('key_file')->getRealPath()), $data['password'], [
            'label' => $data['label'],
            'owner_name' => $data['owner_name'],
            'owner_tin' => $data['owner_tin'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'],
        ], $request->user());

        $audit->record('signing_key.uploaded', ['key' => $key->id, 'label' => $key->label, 'valid_to' => $key->valid_to->toDateString(), 'fingerprint' => substr($key->fingerprint, 0, 16)]);

        return back()->with('status', __('autopilot.keys.uploaded'));
    }

    public function revoke(Request $request, SigningKey $key, Audit $audit, CurrentCompany $company): RedirectResponse
    {
        $this->authorize(Permission::SigningKeysManage->value);

        $key->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_name' => $request->user()->name]);
        $audit->record('signing_key.revoked', ['key' => $key->id, 'label' => $key->label]);

        // Level 3 with server signing needs a usable key.
        $settings = $company->settings();
        if ($settings->autopilot() === AutopilotLevel::Auto && $settings->signer_mode === 'vault' && SigningKey::usable() === null) {
            $settings->forceFill(['autopilot_level' => AutopilotLevel::Prepare])->save();
            $audit->record('autopilot.level_lowered', ['to' => 1], reason: __('autopilot.notes.key_revoked'));
        }

        return back()->with('status', __('autopilot.keys.revoked'));
    }
}
