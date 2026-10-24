<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/** What the agent read from the cabinet's payment form and its confirmation window, before it presses the confirm button. */
class AgentPaymentCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AuthenticateDesktopAgent
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array'],
            'fields.tax_code' => ['present', 'nullable', 'string', 'max:4'],
            'fields.kind' => ['present', 'nullable', 'string', 'max:6'],
            'fields.amount_minor' => ['present', 'nullable', 'integer'],
        ];
    }
}
