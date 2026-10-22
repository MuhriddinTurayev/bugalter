<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SupportAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:'.config('platform.support_reason_min_length'), 'max:1000']];
    }

    public function attributes(): array
    {
        return ['reason' => __('platform.support.reason')];
    }
}
