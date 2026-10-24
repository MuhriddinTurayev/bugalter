<?php

namespace App\Http\Requests\Agent;

use App\Agent\AgentTasks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Progress, or "the accountant must act now" (the E-IMZO password window, a CAPTCHA, ...). */
class AgentEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AuthenticateDesktopAgent
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['progress', 'needs_user'])],
            'message' => ['required', 'string', 'max:300'],
            'waiting_for' => ['nullable', 'required_if:kind,needs_user', Rule::in(AgentTasks::WAITING_FOR)],
        ];
    }
}
