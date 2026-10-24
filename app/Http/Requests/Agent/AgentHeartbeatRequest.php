<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/** What the agent reports about its computer. Diagnostics only; never trusted for a decision. */
class AgentHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AuthenticateDesktopAgent
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:30'],
            'environment' => ['nullable', 'array'],
            'environment.eimzo_reachable' => ['nullable', 'boolean'],
            'environment.eimzo_version' => ['nullable', 'string', 'max:20'],
            'environment.browser' => ['nullable', 'string', 'max:40'],
            'environment.keys_on_disk' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'environment.os' => ['nullable', 'string', 'max:40'],
        ];
    }
}
