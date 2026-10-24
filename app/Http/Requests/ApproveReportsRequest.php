<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Step 4: the reports to approve and submit, each with the version hash shown on the confirmation page, and the
 * person's declaration that they reviewed them. The server binds the approval to those hashes.
 */
class ApproveReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ReportsApprove->value) && $this->user()->can(Permission::ReportsSubmit->value);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'versions' => ['required', 'array', 'min:1', 'max:20'],
            'versions.*' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'declaration' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'versions' => __('tax.workflow.selected_reports'),
            'declaration' => __('tax.workflow.declaration_field'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_keys((array) $this->input('versions', [])) as $id) {
                if (! ctype_digit((string) $id)) {
                    $validator->errors()->add('versions', __('validation.integer', ['attribute' => __('tax.workflow.selected_reports')]));

                    return;
                }
            }
        }];
    }

    /** @return array<int, string> report id => reviewed version hash */
    public function versions(): array
    {
        $versions = [];

        foreach ($this->validated('versions') as $id => $hash) {
            $versions[(int) $id] = $hash;
        }

        return $versions;
    }
}
