<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class AgentFailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AuthenticateDesktopAgent
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'error' => ['required', 'string', 'max:1000'],
            // The identifier the cabinet showed when it was not the company's: lets the owner confirm the link in one click.
            'seen_identifier' => ['nullable', 'string', 'regex:/^(\d{9}|\d{14})$/'],
        ];
    }
}
