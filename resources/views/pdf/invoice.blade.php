@php
    $money = fn ($value) => '€ ' . number_format((float) $value, 2, ',', '.');
    $unitPrice = fn ($value) => '€ ' . number_format((float) $value, (round((float) $value, 2) == (float) $value) ? 2 : 4, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',') . '%';
@endphp
@php
    $customer = $invoice->customer ?? [];
    $isCreditNote = $invoice->type->value === 'credit_note';
    $pensionLabel = $invoice->pension_type === 'TC22' ? 'Rivalsa INPS' : 'Contributo ' . \Illuminate\Support\Str::after((string) $pensionFund, '– ');
@endphp
@extends('pdf.layout')

@section('title', $invoice->type->label() . ' ' . $invoice->displayNumber())

@push('styles')
    .watermark { position: fixed; top: 90mm; left: 0; right: 0; text-align: center; font-size: 80pt; color: #f0f0f0; transform: rotate(-30deg); z-index: -1; }
@endpush

@section('content')
@if ($invoice->isDraft())
    <div class="watermark">BOZZA</div>
@endif

<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>{{ $invoice->type->label() }}</h1>
            <div style="font-size: 11pt;"><strong>{{ $invoice->isDraft() ? 'Bozza – numero assegnato all\'emissione' : 'N. ' . $invoice->number }}</strong></div>
            <div class="muted">del {{ $invoice->issue_date->format('d/m/Y') }}</div>
            @if ($isCreditNote && $invoice->relatedInvoice)
                <div class="small" style="margin-top: 1mm;">A storno della fattura {{ $invoice->relatedInvoice->displayNumber() }} del {{ $invoice->relatedInvoice->issue_date->format('d/m/Y') }}</div>
            @endif
            @if ($invoice->customer_reference)
                <div class="small" style="margin-top: 1mm;">Vs. ordine {{ $invoice->customer_reference }}@if($invoice->customer_reference_date) del {{ $invoice->customer_reference_date->format('d/m/Y') }}@endif</div>
            @endif
            @if ($invoice->cig || $invoice->cup)
                <div class="small">@if($invoice->cig)CIG {{ $invoice->cig }}@endif @if($invoice->cup) CUP {{ $invoice->cup }}@endif</div>
            @endif
            @if ($invoice->title)
                <div style="margin-top: 3mm;"><span class="label">Oggetto</span><br><strong>{{ $invoice->title }}</strong></div>
            @endif
        </td>
        @include('pdf.partials.customer', ['contact' => $invoice->contact, 'showSdi' => true])
    </tr>
</table>

@include('pdf.partials.lines')

<table style="margin-top: 5mm;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding-right: 6mm;">
            @include('pdf.partials.vat-summary')
        </td>
        <td style="width: 45%; vertical-align: top;">
            <table class="totals">
                <tr><td>Imponibile</td><td class="right">{{ $money($totals['subtotal']) }}</td></tr>
                @if ($totals['pension_amount'] != 0)
                    <tr><td>{{ $pensionLabel }} {{ $percent($invoice->pension_rate) }}</td><td class="right">{{ $money($totals['pension_amount']) }}</td></tr>
                @endif
                <tr><td>IVA</td><td class="right">{{ $money($totals['vat_total']) }}</td></tr>
                <tr class="grand"><td>Totale documento</td><td class="right">{{ $money($totals['total']) }}</td></tr>
                @if ($totals['withholding_amount'] != 0)
                    <tr><td>Ritenuta d'acconto {{ $percent($invoice->withholding_rate) }}</td><td class="right">– {{ $money($totals['withholding_amount']) }}</td></tr>
                @endif
                @if ($invoice->split_payment && $totals['vat_total'] != 0)
                    <tr><td>IVA da versare a cura del cliente</td><td class="right">– {{ $money($totals['vat_total']) }}</td></tr>
                @endif
                @if ($totals['amount_due'] != $totals['total'])
                    <tr style="font-weight: bold;"><td>Netto a pagare</td><td class="right">{{ $money($totals['amount_due']) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@php
    $legalNotes = array_filter([
        $isFlatRate ? "Operazione effettuata ai sensi dell'art. 1, commi da 54 a 89, della Legge n. 190/2014 – regime forfettario. Operazione non soggetta a ritenuta d'acconto ai sensi dell'art. 1, comma 67, della Legge n. 190/2014." : null,
        $invoice->split_payment ? 'Scissione dei pagamenti – art. 17-ter DPR 633/1972: l\'IVA è versata direttamente dal cliente all\'Erario.' : null,
        (float) $invoice->stamp_duty_amount > 0 ? 'Imposta di bollo di € ' . number_format((float) $invoice->stamp_duty_amount, 2, ',', '.') . ' assolta in modo virtuale ai sensi del DM 17/06/2014.' : null,
    ]);
@endphp
@if ($legalNotes)
    <div class="notice small" style="margin-top: 5mm;">
        @foreach ($legalNotes as $note)
            <div>{{ $note }}</div>
        @endforeach
    </div>
@endif

@if (! $isCreditNote)
    <table style="margin-top: 6mm;">
        <tr>
            <td class="box" style="width: 100%;">
                <div class="label">Pagamento</div>
                @if ($invoice->payment_terms){{ $invoice->payment_terms }}@endif
                @if ($invoice->due_date) – scadenza {{ $invoice->due_date->format('d/m/Y') }}@endif
                · <strong>{{ $money($totals['amount_due']) }}</strong>
                @if (!empty($company['iban']))
                    <br>IBAN <strong>{{ $company['iban'] }}</strong>@if(!empty($company['bank_name'])) – {{ $company['bank_name'] }}@endif
                @endif
            </td>
        </tr>
    </table>
@endif

@if ($invoice->notes)
    <div style="margin-top: 6mm;">
        <div class="label">Note</div>
        <div class="pre small">{{ $invoice->notes }}</div>
    </div>
@endif

@unless ($invoice->isDraft())
    <p class="small muted" style="margin-top: 8mm;">Copia di cortesia. Il documento fiscalmente valido è la fattura elettronica trasmessa tramite il Sistema di Interscambio (SdI).</p>
@endunless
@endsection
