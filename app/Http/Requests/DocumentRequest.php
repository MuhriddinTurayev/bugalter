<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            ? $this->user()->can('update', $document)
            : $this->user()->can('create', Document::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DocumentType::class)],
            'direction' => ['required', Rule::in(['out', 'in'])],
            'contractor_id' => ['required', Rule::exists('contractors', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,11}([.,]\d{1,3})?$/'],
            'lines.*.unit_price' => ['required', 'string'],
            'lines.*.vat_percent' => ['nullable', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('lines', []) as $index => $line) {
                $quantity = $this->decimal($line['quantity'] ?? null);
                if ($quantity !== null && BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                    $validator->errors()->add("lines.$index.quantity", __('ui.documents.errors.quantity'));
                }

                if (Money::parseToMinor($line['unit_price'] ?? null) === null) {
                    $validator->errors()->add("lines.$index.unit_price", __('ui.documents.errors.price'));
                }

                $vat = $this->decimal($line['vat_percent'] ?? null);
                if ($vat !== null && BigDecimal::of($vat)->isGreaterThan(100)) {
                    $validator->errors()->add("lines.$index.vat_percent", __('ui.documents.errors.vat'));
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'contractor_id' => __('ui.documents.fields.contractor'),
            'issue_date' => __('ui.documents.fields.issue_date'),
            'due_date' => __('ui.documents.fields.due_date'),
            'notes' => __('ui.documents.fields.notes'),
            'lines' => __('ui.documents.fields.lines'),
            'lines.*.name' => __('ui.documents.fields.line_name'),
            'lines.*.quantity' => __('ui.documents.fields.quantity'),
            'lines.*.unit_price' => __('ui.documents.fields.unit_price'),
            'lines.*.vat_percent' => __('ui.documents.fields.vat_percent'),
        ];
    }

    /** Validated input converted to what SaveDocument expects: integers in minor units and basis points. */
    public function documentData(): array
    {
        $data = $this->validated();

        $data['lines'] = array_map(fn (array $line) => [
            'name' => $line['name'],
            'unit' => $line['unit'] ?? null,
            'quantity' => $this->decimal($line['quantity']),
            'unit_price_minor' => Money::parseToMinor($line['unit_price']),
            'vat_rate_bp' => $this->basisPoints($line['vat_percent'] ?? null),
        ], array_values($data['lines']));

        return $data;
    }

    /** Normalised decimal string, or null when empty or not a plain non-negative decimal number. */
    private function decimal(?string $value): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));

        return preg_match('/^\d+(\.\d+)?$/', $value) ? $value : null;
    }

    private function basisPoints(?string $percent): int
    {
        $percent = $this->decimal($percent);

        return $percent === null
            ? 0
            : BigDecimal::of($percent)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
