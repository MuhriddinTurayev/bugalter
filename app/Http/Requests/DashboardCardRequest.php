<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'mode' => ['nullable', Rule::in(['monthly', 'annual'])],
            'series' => ['nullable', Rule::in(['income', 'expense'])],
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        $to = $this->filled('to') ? CarbonImmutable::parse($this->input('to')) : CarbonImmutable::now();
        $from = $this->filled('from')
            ? CarbonImmutable::parse($this->input('from'))
            : $to->startOfMonth()->subMonths(5);

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
