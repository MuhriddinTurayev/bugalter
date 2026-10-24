<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Step 1 of "prepare reports automatically": the reporting month. */
class StartReportRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-reports');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m', 'after_or_equal:2020-01', 'before_or_equal:'.now()->format('Y-m')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['month' => __('tax.workflow.month')];
    }

    public function month(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->validated('month'))->startOfMonth();
    }
}
