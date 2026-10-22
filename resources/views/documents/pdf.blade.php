<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document->number }}</title>
    <style>
        /* DejaVu Sans ships with dompdf and covers Latin (incl. Uzbek oʻ gʻ) and Cyrillic. */
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10px; color: #101418; margin: 0; }
        h1 { font-size: 17px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .row { width: 100%; margin-bottom: 14px; }
        .row td { vertical-align: top; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.lines th { background: #f1f3f5; text-align: left; padding: 6px; font-size: 9px; border-bottom: 1px solid #cfd4da; }
        table.lines td { padding: 6px; border-bottom: 1px solid #e4e6ea; }
        .r { text-align: right; }
        .totals { width: 45%; margin-left: 55%; margin-top: 10px; border-collapse: collapse; }
        .totals td { padding: 3px 6px; }
        .totals .grand td { font-weight: bold; font-size: 12px; border-top: 1px solid #101418; padding-top: 6px; }
        .sign { margin-top: 40px; width: 100%; }
        .sign td { width: 50%; padding-right: 20px; }
        .line { border-bottom: 1px solid #101418; height: 22px; }
    </style>
</head>
<body>
@php
    use App\Support\Money;

    $seller = $document->isIncoming() ? $document->contractor : null;
    $out = ! $document->isIncoming();
@endphp

<table class="row"><tr>
    <td style="width:60%">
        <h1>{{ $document->type->label() }} № {{ $document->number }}</h1>
        <div class="muted">{{ __('ui.documents.fields.issue_date') }}: {{ $document->issue_date->format('d.m.Y') }}@if ($document->due_date) · {{ __('ui.documents.fields.due_date') }}: {{ $document->due_date->format('d.m.Y') }}@endif</div>
    </td>
    <td class="r muted">{{ $company->name }}<br>{{ __('ui.contractors.fields.stir') }}: {{ $company->stir ?? '—' }}</td>
</tr></table>

<table class="row"><tr>
    <td style="width:50%">
        <b>{{ $out ? __('ui.documents.pdf.seller') : __('ui.documents.pdf.buyer') }}</b><br>
        {{ $company->name }}<br>
        <span class="muted">{{ $company->address }}<br>
        {{ __('ui.contractors.fields.stir') }}: {{ $company->stir ?? '—' }}<br>
        {{ __('ui.contractors.fields.bank_account') }}: {{ $company->bank_account ?? '—' }}, {{ __('ui.contractors.fields.mfo') }}: {{ $company->mfo ?? '—' }}</span>
    </td>
    <td style="width:50%">
        <b>{{ $out ? __('ui.documents.pdf.buyer') : __('ui.documents.pdf.seller') }}</b><br>
        {{ $document->contractor->name }}<br>
        <span class="muted">{{ $document->contractor->address }}<br>
        {{ __('ui.contractors.fields.stir') }}: {{ $document->contractor->stir ?? '—' }}<br>
        {{ __('ui.contractors.fields.bank_account') }}: {{ $document->contractor->bank_account ?? '—' }}, {{ __('ui.contractors.fields.mfo') }}: {{ $document->contractor->mfo ?? '—' }}</span>
    </td>
</tr></table>

<table class="lines">
    <thead><tr>
        <th>#</th>
        <th>{{ __('ui.documents.fields.line_name') }}</th>
        <th class="r">{{ __('ui.documents.fields.quantity') }}</th>
        <th class="r">{{ __('ui.documents.fields.unit_price') }}</th>
        <th class="r">{{ __('ui.documents.fields.vat_percent') }}</th>
        <th class="r">{{ __('ui.documents.fields.vat') }}</th>
        <th class="r">{{ __('ui.documents.fields.line_total') }}</th>
    </tr></thead>
    <tbody>
    @foreach ($document->lines as $line)
        <tr>
            <td>{{ $line->position }}</td>
            <td>{{ $line->name }}</td>
            <td class="r">{{ rtrim(rtrim($line->quantity, '0'), '.') }} {{ $line->unit }}</td>
            <td class="r">{{ Money::format($line->unit_price_minor, false) }}</td>
            <td class="r">{{ rtrim(rtrim(number_format($line->vat_rate_bp / 100, 2, ',', ''), '0'), ',') }}%</td>
            <td class="r">{{ Money::format($line->vat_minor, false) }}</td>
            <td class="r">{{ Money::format($line->total_minor, false) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="muted">{{ __('ui.documents.fields.subtotal') }}</td><td class="r">{{ Money::format($document->subtotal_minor) }}</td></tr>
    <tr><td class="muted">{{ __('ui.documents.fields.vat') }}</td><td class="r">{{ Money::format($document->vat_minor) }}</td></tr>
    <tr class="grand"><td>{{ __('ui.documents.fields.total') }}</td><td class="r">{{ Money::format($document->total_minor) }}</td></tr>
</table>

@if ($document->notes)
    <p style="margin-top:16px"><span class="muted">{{ __('ui.documents.fields.notes') }}:</span> {{ $document->notes }}</p>
@endif

<table class="sign"><tr>
    <td><div class="line"></div><span class="muted">{{ __('ui.documents.pdf.director') }}: {{ $company->director_name }}</span></td>
    <td><div class="line"></div><span class="muted">{{ __('ui.documents.pdf.chief_accountant') }}: {{ $company->chief_accountant_name }}</span></td>
</tr></table>
</body>
</html>
