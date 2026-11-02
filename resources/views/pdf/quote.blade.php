@php
    $money = fn ($value) => '€ ' . number_format((float) $value, 2, ',', '.');
    $unitPrice = fn ($value) => '€ ' . number_format((float) $value, (round((float) $value, 2) == (float) $value) ? 2 : 4, ',', '.');
    $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $customer = $quote->customer ?? [];
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Preventivo {{ $quote->number }}</title>
    <style>
        @page { margin: 28mm 16mm 22mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #222; line-height: 1.35; }
        header { position: fixed; top: -22mm; left: 0; right: 0; height: 18mm; }
        footer { position: fixed; bottom: -16mm; left: 0; right: 0; height: 12mm; font-size: 7.5pt; color: #777; text-align: center; border-top: 0.5pt solid #ccc; padding-top: 2mm; }
        .pagenum:after { content: counter(page); }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #666; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .center { text-align: center; }
        h1 { font-size: 16pt; margin: 0 0 1mm 0; color: #3F51B5; }
        .box { border: 0.5pt solid #ccc; border-radius: 2mm; padding: 3mm 4mm; vertical-align: top; }
        .label { font-size: 7.5pt; text-transform: uppercase; color: #888; letter-spacing: 0.3pt; margin-bottom: 1mm; }
        .lines th { background: #3F51B5; color: #fff; font-size: 8pt; font-weight: bold; padding: 2mm 1.5mm; text-align: left; }
        .lines th.right { text-align: right; }
        .lines td { padding: 2mm 1.5mm; border-bottom: 0.5pt solid #e3e3e3; vertical-align: top; }
        .lines tr.section td { background: #eef0fa; font-weight: bold; color: #3F51B5; border-bottom: none; }
        .lines tr.text td { color: #444; }
        .lines tr.optional td { color: #777; font-style: italic; }
        .badge { font-size: 7pt; border: 0.5pt solid #999; border-radius: 1mm; padding: 0 1mm; font-style: normal; }
        .totals td { padding: 1.2mm 2mm; }
        .totals .grand td { font-size: 12pt; font-weight: bold; color: #3F51B5; border-top: 1pt solid #3F51B5; }
        .pre { white-space: pre-line; }
        .description { white-space: pre-line; }
    </style>
</head>
<body>
<header>
    <table>
        <tr>
            <td style="width: 55%; vertical-align: middle;">
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 16mm; max-width: 70mm;">
                @else
                    <strong style="font-size: 13pt;">{{ $company['name'] ?? config('app.name') }}</strong>
                @endif
            </td>
            <td class="right small muted" style="vertical-align: middle;">
                <strong style="color: #222;">{{ $company['name'] ?? '' }}</strong><br>
                {{ $company['address'] ?? '' }} {{ $company['postal_code'] ?? '' }} {{ $company['city'] ?? '' }} @if(!empty($company['province']))({{ $company['province'] }})@endif<br>
                @if(!empty($company['vat_number']))P.IVA {{ $company['vat_number'] }}@endif
                @if(!empty($company['tax_code']) && ($company['tax_code'] ?? '') !== ($company['vat_number'] ?? '')) · C.F. {{ $company['tax_code'] }}@endif
            </td>
        </tr>
    </table>
</header>

<footer>
    {{ $company['name'] ?? '' }}
    @if(!empty($company['email'])) · {{ $company['email'] }}@endif
    @if(!empty($company['phone'])) · Tel. {{ $company['phone'] }}@endif
    @if(!empty($company['pec'])) · PEC {{ $company['pec'] }}@endif
    @if(!empty($company['rea'])) · REA {{ $company['rea'] }}@endif
    @if(!empty($company['share_capital'])) · Cap. soc. {{ $company['share_capital'] }}@endif
    <br>Preventivo {{ $quote->number }} · pagina <span class="pagenum"></span>
</footer>

<table style="margin-bottom: 6mm;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4mm;">
            <h1>Preventivo</h1>
            <div style="font-size: 11pt;"><strong>{{ $quote->number }}</strong></div>
            <div class="muted">del {{ $quote->issue_date->format('d/m/Y') }} · valido fino al {{ $quote->valid_until->format('d/m/Y') }}</div>
            @if ($quote->title)
                <div style="margin-top: 3mm;"><span class="label">Oggetto</span><br><strong>{{ $quote->title }}</strong></div>
            @endif
        </td>
        <td class="box" style="width: 50%;">
            <div class="label">Spett.le</div>
            <strong>{{ $customer['name'] ?? '' }}</strong><br>
            @if(!empty($customer['address'])){{ $customer['address'] }}<br>@endif
            @if(!empty($customer['city_line'])){{ $customer['city_line'] }}@if(!empty($customer['country']) && $customer['country'] !== 'IT') – {{ $customer['country'] }}@endif<br>@endif
            @if(!empty($customer['vat_number']))P.IVA {{ $customer['vat_number'] }}<br>@endif
            @if(!empty($customer['tax_code']) && $customer['tax_code'] !== ($customer['vat_number'] ?? ''))C.F. {{ $customer['tax_code'] }}<br>@endif
            @if ($quote->contact)
                <span class="muted">Alla c.a. {{ $quote->contact->full_name }}</span>
            @endif
        </td>
    </tr>
</table>

@if ($quote->intro)
    <p class="pre" style="margin: 0 0 5mm 0;">{{ $quote->intro }}</p>
@endif

<table class="lines">
    <thead>
        <tr>
            <th style="width: 46%;">Descrizione</th>
            <th class="right" style="width: 9%;">Q.tà</th>
            <th style="width: 6%;">U.m.</th>
            <th class="right" style="width: 13%;">Prezzo</th>
            <th class="right" style="width: 7%;">Sc.</th>
            <th class="right" style="width: 6%;">IVA</th>
            <th class="right" style="width: 13%;">Importo</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            @if ($line->type->value === 'section')
                <tr class="section"><td colspan="7">{{ $line->description }}</td></tr>
            @elseif ($line->type->value === 'text')
                <tr class="text"><td colspan="7" class="description">{{ $line->description }}</td></tr>
            @else
                <tr class="{{ $line->is_optional ? 'optional' : '' }}">
                    <td>
                        @if ($line->code)<span class="muted small">{{ $line->code }}</span><br>@endif
                        <span class="description">{{ $line->description }}</span>
                        @if ($line->is_optional)
                            <br><span class="badge">{{ $line->is_selected ? 'Opzionale – inclusa' : 'Opzionale' }}</span>
                        @endif
                    </td>
                    <td class="right">{{ $qty($line->quantity) }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="right">{{ $unitPrice($line->unit_price) }}</td>
                    <td class="right">{{ (float) $line->discount_percent > 0 ? rtrim(rtrim(number_format((float) $line->discount_percent, 2, ',', ''), '0'), ',') . '%' : '' }}</td>
                    <td class="right">{{ $line->vat_nature ?: rtrim(rtrim(number_format((float) $line->vat_rate, 2, ',', ''), '0'), ',') . '%' }}</td>
                    <td class="right">{{ $money($line->total) }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

<table style="margin-top: 5mm;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding-right: 6mm;">
            <div class="label">Riepilogo IVA</div>
            <table class="small">
                @foreach ($totals['vat_summary'] as $group)
                    <tr>
                        <td>{{ $group['nature'] ? 'Natura ' . $group['nature'] : 'IVA ' . rtrim(rtrim(number_format($group['rate'], 2, ',', ''), '0'), ',') . '%' }}</td>
                        <td class="right">imponibile {{ $money($group['taxable']) }}</td>
                        <td class="right">imposta {{ $money($group['vat']) }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($totals['optional_total'] > 0)
                <p class="small muted" style="margin-top: 3mm;">Voci opzionali non incluse nel totale: {{ $money($totals['optional_total']) }} + IVA.</p>
            @endif
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

@if ($legalReferences->isNotEmpty())
    <p class="small muted" style="margin-top: 4mm;">{{ $legalReferences->implode(' · ') }}</p>
@endif

<table style="margin-top: 6mm;">
    <tr>
        @if ($quote->payment_terms)
            <td class="box" style="width: 50%;">
                <div class="label">Pagamento</div>
                {{ $quote->payment_terms }}
                @if (!empty($company['iban']))
                    <br><span class="small">IBAN {{ $company['iban'] }}@if(!empty($company['bank_name'])) – {{ $company['bank_name'] }}@endif</span>
                @endif
            </td>
        @endif
        @if ($quote->owner)
            <td class="box" style="width: 50%;">
                <div class="label">Referente</div>
                {{ $quote->owner->name }}<br><span class="small">{{ $quote->owner->email }}</span>
            </td>
        @endif
    </tr>
</table>

@if ($quote->terms)
    <div style="margin-top: 6mm;">
        <div class="label">Condizioni</div>
        <div class="pre small">{{ $quote->terms }}</div>
    </div>
@endif

<table style="margin-top: 12mm;">
    <tr>
        <td style="width: 50%;"></td>
        <td class="center small muted" style="width: 50%;">
            Per accettazione<br><br><br>
            <span style="border-top: 0.5pt solid #999; padding-top: 1mm; display: inline-block; width: 70mm;">Timbro e firma</span>
        </td>
    </tr>
</table>
</body>
</html>
