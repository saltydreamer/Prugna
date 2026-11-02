@php
    $money = fn ($value) => '€ ' . number_format((float) $value, 2, ',', '.');
    $unitPrice = fn ($value) => '€ ' . number_format((float) $value, (round((float) $value, 2) == (float) $value) ? 2 : 4, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',') . '%';
@endphp
@extends('pdf.layout')

@section('title', "Conferma d'ordine " . $order->number)

@section('content')
<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>Conferma d'ordine</h1>
            <div style="font-size: 11pt;"><strong>{{ $order->number }}</strong></div>
            <div class="muted">del {{ $order->order_date->format('d/m/Y') }}</div>
            @if ($order->customer_reference)
                <div class="small" style="margin-top: 1mm;">Vs. riferimento: {{ $order->customer_reference }}@if($order->customer_reference_date) del {{ $order->customer_reference_date->format('d/m/Y') }}@endif</div>
            @endif
            @if ($order->quote)
                <div class="small">Rif. nostro preventivo {{ $order->quote->number }}</div>
            @endif
            @if ($order->title)
                <div style="margin-top: 3mm;"><span class="label">Oggetto</span><br><strong>{{ $order->title }}</strong></div>
            @endif
        </td>
        @include('pdf.partials.customer', ['customer' => $order->customer ?? [], 'contact' => $order->contact])
    </tr>
</table>

<p style="margin: 0 0 5mm 0;">Vi ringraziamo per l'ordine, che confermiamo alle condizioni di seguito riportate.</p>

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
            <div class="label">Consegna prevista</div>
            {{ $order->delivery_date ? $order->delivery_date->format('d/m/Y') : 'Da concordare' }}
        </td>
        @if ($order->payment_terms)
            <td class="box" style="width: 50%;">
                <div class="label">Pagamento</div>
                {{ $order->payment_terms }}
                @if (!empty($company['iban']))
                    <br><span class="small">IBAN {{ $company['iban'] }}@if(!empty($company['bank_name'])) – {{ $company['bank_name'] }}@endif</span>
                @endif
            </td>
        @endif
    </tr>
</table>

@if ($order->notes)
    <div style="margin-top: 6mm;">
        <div class="label">Note</div>
        <div class="pre small">{{ $order->notes }}</div>
    </div>
@endif

<table style="margin-top: 12mm;">
    <tr>
        <td class="small muted" style="width: 50%;">
            @if ($order->owner)Referente: {{ $order->owner->name }} · {{ $order->owner->email }}@endif
        </td>
        <td class="center small muted" style="width: 50%;">
            Per accettazione<br><br><br>
            <span style="border-top: 0.5pt solid #999; padding-top: 1mm; display: inline-block; width: 70mm;">Timbro e firma</span>
        </td>
    </tr>
</table>
@endsection
