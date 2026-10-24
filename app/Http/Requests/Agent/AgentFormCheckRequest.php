<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/** The values the agent read back from the filled cabinet form, before signing. Laravel compares them. */
class AgentFormCheckRequest extends FormRequest
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
            'fields.tin' => ['present', 'nullable', 'string', 'max:14'],
            'fields.type' => ['present', 'nullable', 'string', 'max:20'],
            'fields.period_start' => ['present', 'nullable', 'date_format:Y-m-d'],
            'fields.period_end' => ['present', 'nullable', 'date_format:Y-m-d'],
            'fields.base_minor' => ['present', 'nullable', 'integer'],
            'fields.tax_minor' => ['present', 'nullable', 'integer'],
            'fields.payable_minor' => ['present', 'nullable', 'integer'],
        ];
    }
}
