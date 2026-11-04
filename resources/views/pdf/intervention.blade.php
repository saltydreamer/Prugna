@php
    $hours = fn (int $minutes) => \App\Actions\Interventions\BillInterventions::hoursLabel($minutes);
    $duration = function (?int $minutes) {
        $minutes = (int) $minutes;
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h ? ($m ? "{$h} h {$m} min" : "{$h} h") : "{$m} min";
    };
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
@endphp
@extends('pdf.layout')

@section('title', 'Rapportino ' . $intervention->number)

@push('styles')
    .facts td { padding: 1.5mm 2mm; border-bottom: 0.5pt solid #e3e3e3; }
    .facts td.k { color: #666; width: 35%; }
    .signature { height: 28mm; border-bottom: 0.5pt solid #999; text-align: center; }
@endpush

@section('content')
<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>Rapportino d'intervento</h1>
            <div style="font-size: 11pt;"><strong>{{ $intervention->number }}</strong></div>
            <div class="muted">del {{ $start->format('d/m/Y') }}</div>
            @if ($intervention->order)
                <div class="small" style="margin-top: 1mm;">Rif. ordine {{ $intervention->order->number }}@if($intervention->order->title) – {{ $intervention->order->title }}@endif</div>
            @endif
        </td>
        @include('pdf.partials.customer', ['customer' => $customer, 'contact' => $intervention->contact])
    </tr>
</table>

<table class="facts" style="margin-bottom: 6mm;">
    <tr><td class="k">Tipo di intervento</td><td>{{ $intervention->category?->name }} – {{ mb_strtolower($intervention->mode->label()) }}</td></tr>
    @if ($intervention->location)
        <tr><td class="k">Luogo</td><td>{{ $intervention->location->name }} – {{ $intervention->location->full_address }}</td></tr>
    @endif
    <tr><td class="k">Data e ora</td><td>{{ $start->format('d/m/Y') }}, dalle {{ $start->format('H:i') }}@if($end) alle {{ $end->format('H:i') }}@if(! $end->isSameDay($start)) del {{ $end->format('d/m/Y') }}@endif @endif</td></tr>
    <tr><td class="k">Durata</td><td>{{ $duration($intervention->duration_minutes) }}@if($intervention->billable && $intervention->billableMinutes() !== $intervention->duration_minutes) · conteggiate {{ $hours($intervention->billableMinutes()) }}@endif</td></tr>
    @if ($intervention->isOnsite() && $intervention->travel_minutes)
        <tr><td class="k">Viaggio</td><td>{{ $duration($intervention->travel_minutes) }}@if((float) $intervention->km > 0) · {{ $qty($intervention->km) }} km @endif</td></tr>
    @elseif ($intervention->isOnsite() && (float) $intervention->km > 0)
        <tr><td class="k">Km percorsi</td><td>{{ $qty($intervention->km) }} km</td></tr>
    @endif
    @if ($intervention->isOnsite() && $intervention->callout)
        <tr><td class="k">Diritto di uscita</td><td>Sì</td></tr>
    @endif
    <tr><td class="k">Tecnico</td><td>{{ $intervention->technician?->name ?? '—' }}</td></tr>
    @if ($intervention->contract)
        <tr><td class="k">Monte ore</td><td>{{ $intervention->contract->number }} – {{ $intervention->contract->title }}: scalate {{ $hours($intervention->contract_minutes) }}, residuo {{ $hours((int) $contractRemaining) }}</td></tr>
    @endif
    @if (! $intervention->billable)
        <tr><td class="k">Addebito</td><td>Intervento non addebitato</td></tr>
    @endif
</table>

<div class="label">Lavoro svolto</div>
<div class="box description" style="margin-bottom: 6mm; min-height: 25mm;">{{ $intervention->description ?: '—' }}</div>

@if ($intervention->isOnsite() && $intervention->materials->isNotEmpty())
    <div class="label">Materiali utilizzati</div>
    <table class="lines" style="margin-bottom: 6mm;">
        <thead>
            <tr><th>Descrizione</th><th class="right" style="width: 25mm;">Quantità</th></tr>
        </thead>
        <tbody>
            @foreach ($intervention->materials as $material)
                <tr><td>@if($material->code)<span class="muted">{{ $material->code }}</span> @endif{{ $material->description }}</td><td class="right">{{ $qty($material->quantity) }} {{ $material->unit }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

<table style="margin-top: 8mm; page-break-inside: avoid;">
    <tr>
        <td style="width: 50%; vertical-align: bottom; padding-right: 6mm;">
            <div class="label">Il tecnico</div>
            <div style="padding-top: 18mm; border-bottom: 0.5pt solid #999;">{{ $intervention->technician?->name }}</div>
        </td>
        <td style="width: 50%; vertical-align: bottom;">
            <div class="label">Firma del cliente per accettazione</div>
            <div class="signature">
                @if ($signature)
                    <img src="{{ $signature }}" style="max-height: 26mm; max-width: 80mm;">
                @endif
            </div>
            @if ($signedAt)
                <div class="small muted">{{ $intervention->signed_by_name }} · firmato il {{ $signedAt->format('d/m/Y') }} alle {{ $signedAt->format('H:i') }}</div>
            @else
                <div class="small muted">Nome e cognome</div>
            @endif
        </td>
    </tr>
</table>
@endsection
