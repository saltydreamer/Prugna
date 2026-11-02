@php
    $money = fn ($value) => '€ ' . number_format((float) $value, 2, ',', '.');
    $unitPrice = fn ($value) => '€ ' . number_format((float) $value, (round((float) $value, 2) == (float) $value) ? 2 : 4, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',') . '%';
@endphp
@extends('pdf.layout')

@section('title', 'Ordine ' . $purchaseOrder->number)

@section('content')
<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>Ordine di acquisto</h1>
            <div style="font-size: 11pt;"><strong>{{ $purchaseOrder->number }}</strong></div>
            <div class="muted">del {{ $purchaseOrder->order_date->format('d/m/Y') }}</div>
            @if ($purchaseOrder->supplier_reference)
                <div class="small" style="margin-top: 1mm;">Rif. vostra offerta: {{ $purchaseOrder->supplier_reference }}</div>
            @endif
            @if ($purchaseOrder->title)
                <div style="margin-top: 3mm;"><span class="label">Oggetto</span><br><strong>{{ $purchaseOrder->title }}</strong></div>
            @endif
        </td>
        @include('pdf.partials.customer', ['customer' => $purchaseOrder->supplier ?? [], 'contact' => $purchaseOrder->contact])
    </tr>
</table>

<p style="margin: 0 0 5mm 0;">Vi preghiamo di fornirci quanto segue alle condizioni indicate, dandocene conferma.</p>

@include('pdf.partials.lines')

<table style="margin-top: 5mm;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding-right: 6mm;">
            @include('pdf.partials.vat-summary')
        </td>
        <td style="width: 45%; vertical-align: top;">
            <table class="totals">
                <tr><td>Imponibile</td><td class="right">{{ $money($totals['subtotal']) }}</td></tr>
                <tr><td>IVA</td><td class="right">{{ $money($totals['vat_total']) }}</td></tr>
                <tr class="grand"><td>Totale</td><td class="right">{{ $money($totals['total']) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table style="margin-top: 6mm;">
    <tr>
        <td class="box" style="width: 50%;">
            <div class="label">Consegna</div>
            {{ $purchaseOrder->expected_date ? 'Entro il ' . $purchaseOrder->expected_date->format('d/m/Y') : 'Da concordare' }}<br>
            <span class="small">presso {{ $company['name'] ?? '' }}, {{ $company['address'] ?? '' }} {{ $company['postal_code'] ?? '' }} {{ $company['city'] ?? '' }}</span>
        </td>
        @if ($purchaseOrder->payment_terms)
            <td class="box" style="width: 50%;">
                <div class="label">Pagamento</div>
                {{ $purchaseOrder->payment_terms }}
            </td>
        @endif
    </tr>
</table>

@if ($purchaseOrder->notes)
    <div style="margin-top: 6mm;">
        <div class="label">Note</div>
        <div class="pre small">{{ $purchaseOrder->notes }}</div>
    </div>
@endif

<p class="small muted" style="margin-top: 8mm;">
    Indicate il numero d'ordine {{ $purchaseOrder->number }} su conferma, documento di trasporto e fattura.
    @if ($purchaseOrder->owner)<br>Referente: {{ $purchaseOrder->owner->name }} · {{ $purchaseOrder->owner->email }}@endif
</p>
@endsection
