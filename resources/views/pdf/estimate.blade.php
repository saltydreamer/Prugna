@php
    $money = fn ($value) => '€ ' . number_format((float) $value, 2, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $vatNote = $vatFree ? 'Importi senza IVA: operazione in regime forfettario.' : 'Importi IVA esclusa.';
@endphp
@extends('pdf.layout')

@section('title', 'Proposta ' . $estimate->number)

@push('styles')
    h2 { font-size: 11.5pt; color: #3F51B5; margin: 7mm 0 2.5mm 0; }
    .included td { padding: 1.4mm 1.5mm; border-bottom: 0.5pt solid #e3e3e3; vertical-align: top; }
    .included tr.category td { background: #eef0fa; font-weight: bold; color: #3F51B5; border-bottom: none; padding-top: 2mm; }
    .plan td { padding: 1.8mm 2mm; border-bottom: 0.5pt solid #e3e3e3; }
    .plan tr.total td { font-weight: bold; border-top: 1pt solid #3F51B5; border-bottom: none; }
    .fee { font-size: 20pt; font-weight: bold; color: #3F51B5; }
    .advantages li { margin-bottom: 1.6mm; }
@endpush

@section('content')
<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>Proposta</h1>
            <div style="font-size: 11pt;"><strong>{{ $estimate->number }}</strong></div>
            <div class="muted">del {{ $estimate->updated_at->format('d/m/Y') }}</div>
            <div style="margin-top: 3mm;"><span class="label">Oggetto</span><br><strong>{{ $estimate->title }}</strong></div>
        </td>
        <td class="box" style="width: 50%;">
            <div class="label">Spett.le</div>
            @if ($customer)
                <strong>{{ $customer->name }}</strong><br>
                @if ($customer->vat_number)P.IVA {{ $customer->vat_number }}<br>@endif
            @else
                <span class="muted">—</span>
            @endif
        </td>
    </tr>
</table>

@if ($estimate->intro)
    <p class="pre" style="margin: 0 0 4mm 0;">{{ $estimate->intro }}</p>
@endif

<div class="notice" style="margin-bottom: 2mm;">
<table>
    <tr>
        <td style="width: 55%; vertical-align: middle;">
            <div class="label">Canone mensile</div>
            <div class="fee">{{ $money($totals['monthly']) }}</div>
            <div class="muted small">per {{ $totals['months'] }} mesi · {{ $vatNote }}</div>
        </td>
        <td style="width: 45%; vertical-align: middle;" class="right">
            @if ($totals['upfront'] > 0)
                <div class="muted">Anticipo alla firma {{ $money($totals['upfront']) }}</div>
            @else
                <div class="muted">Nessun anticipo</div>
            @endif
            <div class="muted">Totale del contratto {{ $money($totals['contract_total']) }}</div>
        </td>
    </tr>
</table>
</div>

<h2>Cosa è incluso</h2>
<table class="included">
    @foreach ($groups as $category => $items)
        <tr class="category"><td colspan="2">{{ $category }}</td></tr>
        @foreach ($items as $item)
            <tr>
                <td style="width: 12%;" class="right">{{ $qty($item->quantity) }} ×</td>
                <td>{{ $item->description }}</td>
            </tr>
        @endforeach
    @endforeach
</table>

<h2>Piano finanziario</h2>
<table class="plan">
    <tr>
        <td class="label" style="width: 40%;">Periodo</td>
        <td class="label right">Anticipo</td>
        <td class="label right">Canoni</td>
        <td class="label right">Totale</td>
    </tr>
    @foreach ($years as $year)
        <tr>
            <td>{{ $year['year'] }}° anno <span class="muted small">({{ $year['months'] }} canoni)</span></td>
            <td class="right">{{ $year['upfront'] > 0 ? $money($year['upfront']) : '—' }}</td>
            <td class="right">{{ $money($year['fees']) }}</td>
            <td class="right">{{ $money($year['upfront'] + $year['fees']) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>Totale {{ $totals['months'] }} mesi</td>
        <td class="right">{{ $totals['upfront'] > 0 ? $money($totals['upfront']) : '—' }}</td>
        <td class="right">{{ $money($totals['monthly'] * $totals['months']) }}</td>
        <td class="right">{{ $money($totals['contract_total']) }}</td>
    </tr>
</table>
<p class="muted small" style="margin-top: 2mm;">
    Canone fisso per tutta la durata, fatturato mensilmente in anticipo{{ $estimate->starts_on ? ' a partire dal ' . $estimate->starts_on->format('d/m/Y') : ' dal mese successivo alla firma' }}. {{ $vatNote }}
</p>

@if ($advantages->isNotEmpty())
    <h2>I vantaggi della soluzione</h2>
    <ul class="advantages">
        @foreach ($advantages as $advantage)
            <li>{{ $advantage }}</li>
        @endforeach
    </ul>
@endif

@if ($estimate->notes)
    <h2>Condizioni</h2>
    <p class="pre">{{ $estimate->notes }}</p>
@endif
@endsection
