<?php

namespace App\Http\Requests;

use App\Enums\ContractorType;
use App\Models\Contractor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contractor = $this->route('contractor');

        return $contractor instanceof Contractor
            ? $this->user()->can('update', $contractor)
            : $this->user()->can('create', Contractor::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'stir' => ['nullable', 'regex:/^(\d{9}|\d{14})$/'],
            'type' => ['required', Rule::enum(ContractorType::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'regex:/^\d{20}$/'],
            'mfo' => ['nullable', 'regex:/^\d{5}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return collect(['name', 'stir', 'type', 'phone', 'email', 'address', 'bank_name', 'bank_account', 'mfo', 'notes'])
            ->mapWithKeys(fn (string $field) => [$field => __('ui.contractors.fields.'.$field)])
            ->all();
    }

    public function messages(): array
    {
        return [
            'stir.regex' => __('ui.contractors.errors.stir'),
            'bank_account.regex' => __('ui.contractors.errors.bank_account'),
            'mfo.regex' => __('ui.contractors.errors.mfo'),
        ];
    }
}
