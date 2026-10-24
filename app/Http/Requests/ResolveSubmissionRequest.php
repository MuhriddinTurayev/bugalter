<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A person settles a delivery whose outcome is open, after checking the tax cabinet; the note is required. */
class ResolveSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ReportsApprove->value) && $this->user()->can(Permission::ReportsSubmit->value);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['accepted', 'rejected', 'not_received'])],
            'receipt' => ['nullable', 'required_if:outcome,accepted', 'string', 'max:80', 'regex:/^[\pL\pN\-\/._ ]+$/u'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'outcome' => __('tax.resolve.outcome'),
            'receipt' => __('tax.receipt'),
            'note' => __('tax.resolve.note'),
        ];
    }
}
