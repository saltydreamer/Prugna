@php
    $hours = fn (int $minutes) => \App\Actions\Interventions\BillInterventions::hoursLabel($minutes);
    $duration = function (?int $minutes) {
        $minutes = (int) $minutes;
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h ? ($m ? "{$h} h {$m} min" : "{$h} h") : "{$m} min";
    };
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $count = fn (int $n) => $n === 1 ? '1 intervento' : "{$n} interventi";
    $timezone = config('app.display_timezone');
    $several = $customers->count() > 1;
@endphp
@extends('pdf.layout')

@section('title', 'Riepilogo interventi')

@push('styles')
    .facts td { padding: 1.5mm 2mm; border-bottom: 0.5pt solid #e3e3e3; }
    .facts td.k { color: #666; width: 35%; }
    .customer-name { font-size: 10.5pt; font-weight: bold; color: #3F51B5; margin: 5mm 0 1.5mm 0; }
    .lines tr.subtotal td { font-weight: bold; border-bottom: none; }
    .lines .note { font-size: 8pt; color: #666; margin-top: 1mm; }
@endpush

@section('content')
<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>Riepilogo interventi</h1>
            <div style="font-size: 11pt;"><strong>{{ $period }}</strong></div>
            <div class="muted">{{ $count($totals['count']) }}@if($several) · {{ $customers->count() }} clienti @endif</div>
        </td>
        @if ($customer)
            @include('pdf.partials.customer', ['customer' => $customer])
        @else
            <td style="width: 50%;"></td>
        @endif
    </tr>
</table>

<table class="facts" style="margin-bottom: 6mm;">
    <tr><td class="k">Tempo di lavoro</td><td>{{ $duration($totals['minutes']) }}@if($totals['billable_minutes'] !== $totals['minutes']) · conteggiate {{ $hours($totals['billable_minutes']) }}@endif</td></tr>
    @if ($totals['travel_minutes'])
        <tr><td class="k">Viaggio</td><td>{{ $duration($totals['travel_minutes']) }}@if($totals['km'] > 0) · {{ $qty($totals['km']) }} km @endif</td></tr>
    @elseif ($totals['km'] > 0)
        <tr><td class="k">Km percorsi</td><td>{{ $qty($totals['km']) }} km</td></tr>
    @endif
    @foreach ($contracts as $row)
        <tr><td class="k">Monte ore {{ $row['contract']->number }}</td><td>{{ $row['contract']->title }}: scalate {{ $hours($row['minutes']) }} da questi interventi, residuo al {{ now($timezone)->format('d/m/Y') }} {{ $hours($row['remaining']) }}</td></tr>
    @endforeach
</table>

@foreach ($customers as $group)
    @if ($several)
        <div class="customer-name">{{ $group['organization']?->name ?? '—' }} <span class="small muted" style="font-weight: normal;">· {{ $count($group['totals']['count']) }}</span></div>
    @endif
    <table class="lines" style="margin-bottom: 4mm;">
        <thead>
            <tr>
                <th style="width: 13%;">Data</th>
                <th style="width: 21%;">Intervento</th>
                <th style="width: 48%;">Lavoro svolto</th>
                <th class="right" style="width: 18%;">Durata</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($group['interventions'] as $intervention)
                @php
                    $start = $intervention->localStart();
                    $end = $intervention->endsAt()?->copy()->setTimezone($timezone);
                    $onsite = $intervention->isOnsite();
                @endphp
                <tr>
                    <td class="num">
                        {{ $start->format('d/m/Y') }}<br>
                        <span class="small muted">{{ $start->format('H:i') }}@if($end)–{{ $end->format('H:i') }}@endif</span>
                    </td>
                    <td>
                        <strong>{{ $intervention->number }}</strong><br>
                        <span class="small">{{ $intervention->category?->name }} – {{ mb_strtolower($intervention->mode->label()) }}</span>
                        @if ($intervention->technician)
                            <br><span class="small muted">{{ $intervention->technician->name }}</span>
                        @endif
                    </td>
                    <td>
                        <div class="description">{{ $intervention->description ?: '—' }}</div>
                        @if ($onsite && $intervention->location)
                            <div class="note">{{ $intervention->location->name }} – {{ $intervention->location->full_address }}</div>
                        @endif
                        @if ($onsite && $intervention->materials->isNotEmpty())
                            <div class="note">Materiali: {{ $intervention->materials->map(fn ($material) => $material->description.' ('.trim($qty($material->quantity).' '.$material->unit).')')->implode('; ') }}</div>
                        @endif
                        @if ($intervention->contract && $intervention->contract_minutes > 0)
                            <div class="note">Monte ore {{ $intervention->contract->number }}: scalate {{ $hours($intervention->contract_minutes) }}</div>
                        @endif
                    </td>
                    <td class="right">
                        <span class="num">{{ $duration($intervention->duration_minutes) }}</span>
                        @if (! $intervention->billable)
                            <br><span class="small muted">non addebitato</span>
                        @elseif ($intervention->billableMinutes() !== $intervention->duration_minutes)
                            <br><span class="small muted">conteggiate {{ $hours($intervention->billableMinutes()) }}</span>
                        @endif
                        @if ($onsite && $intervention->travel_minutes)
                            <br><span class="small muted">viaggio {{ $duration($intervention->travel_minutes) }}</span>
                        @endif
                        @if ($onsite && (float) $intervention->km > 0)
                            <br><span class="small muted">{{ $qty($intervention->km) }} km</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            @if ($several)
                <tr class="subtotal">
                    <td colspan="3" class="right">Totale {{ $group['organization']?->name }}</td>
                    <td class="right num">{{ $duration($group['totals']['minutes']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
@endforeach

@endsection
